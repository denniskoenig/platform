<?php declare(strict_types=1);

namespace Shopware\Core\Content\Media\DataAbstractionLayer;

use function Flag\next1309;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\Message\DeleteFileMessage;
use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregatorResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class MediaRepositoryDecorator implements EntityRepositoryInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    private $innerRepo;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var FilesystemInterface
     */
    private $filesystem;

    /**
     * @var EntityRepositoryInterface
     */
    private $thumbnailRepository;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    public function __construct(
        EntityRepositoryInterface $innerRepo,
        EventDispatcherInterface $eventDispatcher,
        UrlGeneratorInterface $urlGenerator,
        FilesystemInterface $filesystem,
        EntityRepositoryInterface $thumbnailRepository,
        MessageBusInterface $messageBus
    ) {
        $this->innerRepo = $innerRepo;
        $this->eventDispatcher = $eventDispatcher;
        $this->filesystem = $filesystem;
        $this->urlGenerator = $urlGenerator;
        $this->thumbnailRepository = $thumbnailRepository;
        $this->messageBus = $messageBus;
    }

    public function delete(array $ids, Context $context): EntityWrittenContainerEvent
    {
        $affectedMedia = $this->search(new Criteria($this->getRawIds($ids)), $context);

        if ($affectedMedia->count() === 0) {
            $event = EntityWrittenContainerEvent::createWithDeletedEvents([], $context, []);
            $this->eventDispatcher->dispatch(EntityWrittenContainerEvent::NAME, $event);

            return $event;
        }

        $filesToDelete = [];
        $thumbnailsToDelete = [];

        /** @var MediaEntity $mediaEntity */
        foreach ($affectedMedia as $mediaEntity) {
            if (!$mediaEntity->hasFile()) {
                continue;
            }
            $filesToDelete[] = $this->urlGenerator->getRelativeMediaUrl($mediaEntity);
            $thumbnailsToDelete = array_merge($thumbnailsToDelete, $mediaEntity->getThumbnails()->getIds());
        }

        if (next1309()) {
            $deleteMsg = new DeleteFileMessage();
            $deleteMsg->setFiles($filesToDelete);
            $this->messageBus->dispatch($deleteMsg);
        } else {
            foreach ($filesToDelete as $file) {
                try {
                    $this->filesystem->delete($file);
                } catch (FileNotFoundException $e) {
                    //ignore file is already deleted
                }
            }
        }

        $this->thumbnailRepository->delete($thumbnailsToDelete, $context);

        return $this->innerRepo->delete($ids, $context);
    }

    // Unchanged methods

    public function aggregate(Criteria $criteria, Context $context): AggregatorResult
    {
        return $this->innerRepo->aggregate($criteria, $context);
    }

    public function searchIds(Criteria $criteria, Context $context): IdSearchResult
    {
        return $this->innerRepo->searchIds($criteria, $context);
    }

    public function clone(string $id, Context $context, ?string $newId = null): EntityWrittenContainerEvent
    {
        return $this->innerRepo->clone($id, $context, $newId);
    }

    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        return $this->innerRepo->search($criteria, $context);
    }

    public function update(array $data, Context $context): EntityWrittenContainerEvent
    {
        return $this->innerRepo->update($data, $context);
    }

    public function upsert(array $data, Context $context): EntityWrittenContainerEvent
    {
        return $this->innerRepo->upsert($data, $context);
    }

    public function create(array $data, Context $context): EntityWrittenContainerEvent
    {
        return $this->innerRepo->create($data, $context);
    }

    public function createVersion(string $id, Context $context, ?string $name = null, ?string $versionId = null): string
    {
        return $this->innerRepo->createVersion($id, $context, $name, $versionId);
    }

    public function merge(string $versionId, Context $context): void
    {
        $this->innerRepo->merge($versionId, $context);
    }

    private function getRawIds(array $ids)
    {
        return array_map(
            function ($idArray) {
                return $idArray['id'];
            },
            $ids
        );
    }
}
