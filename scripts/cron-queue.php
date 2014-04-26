<?php
require_once __DIR__ . '/../src/core.php';

function processQueue($queue, $count, $maxAttempts, $logger, $callback)
{
	$processed = 0;
	while ($processed < $count)
	{
		$queueItem = $queue->peek();
		if ($queueItem === null)
			break;

		$key = $queueItem->item;
		$errors = false;

		try
		{
			$countAsProcessed = $callback($key);
		}
		catch (BadProcessorKeyException $e)
		{
			$logger->log('error: ' . $e->getMessage());
		}
		catch (DocumentException $e)
		{
			$logger->log('error: ' . $e->getMessage());
			$errors = true;
		}
		catch (Exception $e)
		{
			$logger->log('error');
			$logger->log($e);
			$errors = true;
		}

		if (!$errors)
		{
			$queue->dequeue();
		}
		else
		{
			$queue->dequeue();
			$enqueueAtStart = $queueItem->attempts < $maxAttempts;
			if ($enqueueAtStart)
				$queueItem->attempts ++;
			else
				$queueItem->attempts = 0;
			$queue->enqueue($queueItem, $enqueueAtStart);
		}

		if ($countAsProcessed)
			++ $processed;
	}
}

CronRunner::run(__FILE__, function($logger)
{
	$userProcessor = new UserProcessor();
	$mediaProcessors =
	[
		Media::Anime => new AnimeProcessor(),
		Media::Manga => new MangaProcessor()
	];

	$userQueue = new Queue(Config::$userQueuePath);
	$mediaQueue = new Queue(Config::$mediaQueuePath);

	Downloader::setLogger($logger);

	#process users
	processQueue(
		$userQueue,
		Config::$usersPerCronRun,
		Config::$userQueueMaxAttempts,
		$logger,
		function($userName) use ($userProcessor, $mediaQueue, $logger)
		{
			Database::selectUser($userName);
			$logger->log('Processing user %s... ', $userName);

			#check if processed too soon
			$query = 'SELECT 0 FROM user WHERE LOWER(name) = LOWER(?)' .
				' AND processed >= DATETIME("now", "-' . Config::$userQueueMinWait . ' minutes")';
			if (R::getAll($query, [$userName]))
			{
				$logger->log('too soon');
				return false;
			}

			#process the user
			$userContext = $userProcessor->process($userName);

			#remove associated cache
			$cache = new Cache();
			$cache->setPrefix($userName);
			foreach ($cache->getAllFiles() as $path)
			{
				unlink($path);
			}

			#append media to queue
			$mediaIds = [];
			foreach (Media::getConstList() as $media)
			{
				foreach ($userContext->user->getMixedUserMedia($media) as $entry)
				{
					$mediaIds []= TextHelper::serializeMediaId($entry);
				}
			}

			$mediaQueue->enqueueMultiple(array_map(function($mediaId)
				{
					return new QueueItem($mediaId);
				}, $mediaIds));

			$logger->log('ok');
			return true;
		});

	#process media
	processQueue(
		$mediaQueue,
		Config::$mediaPerCronRun,
		Config::$mediaQueueMaxAttempts,
		$logger,
		function($key) use ($mediaProcessors, $logger)
		{
			list ($media, $malId) = TextHelper::deserializeMediaId($key);
			$logger->log('Processing %s #%d... ', Media::toString($media), $malId);

			#check if processed too soon
			$query = 'SELECT 0 FROM media WHERE media = ? AND mal_id = ?' .
				' AND processed >= DATETIME("now", "-' . Config::$mediaQueueMinWait . ' minutes")';
			if (R::getAll($query, [$media, $malId]))
			{
				$logger->log('too soon');
				return false;
			}

			#process the media
			$mediaProcessors[$media]->process($malId);

			$logger->log('ok');
			return true;
		});
});