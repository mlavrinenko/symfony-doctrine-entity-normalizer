<?php

namespace Maximaster\SymfonyDoctrineEntityNormalizer;

use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException as OrmMappingException;
use Doctrine\Common\Persistence\Mapping\MappingException as PersistenceMappingException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

class IdFetcher
{
    /** @var ClassMetadataFactory */
    protected $metadataFactory;

    /** @var PropertyAccessorInterface */
    protected $accessor;

    public function __construct(EntityManagerInterface $em, PropertyAccessorInterface $accessor)
    {
        $this->metadataFactory = $em->getMetadataFactory();
        $this->accessor = $accessor;
    }

    public function getIdField(string $class)
    {
        try {
            $metadata = $this->metadataFactory->getMetadataFor($class);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (
            OrmMappingException|PersistenceMappingException $e
        ) {
            return null;
        }

        $idFields = $metadata->getIdentifierFieldNames();
        if (count($idFields) === 1) {
            return $idFields[0];
        }

        return null;
    }

    public function fetchId(object $object)
    {
        if ($idField = $this->getIdField(get_class($object))) {
            return $this->accessor->getValue($object, $idField);
        }

        return null;
    }
}
