<?php

namespace Maximaster\SymfonyDoctrineEntityNormalizer;

use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException as OrmMappingException;
use Doctrine\Common\Persistence\Mapping\MappingException as PersistenceMappingException;
use Doctrine\ORM\ORMException;
use Exception;
use ReflectionClass;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyInfoExtractorInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class ObjectFromDatabaseNormalizer extends ObjectNormalizer
{
    /** @var EntityManagerInterface */
    protected $em;

    /** @var ClassMetadataFactory */
    protected $metadataFactory;

    /** @var IdFetcher */
    protected $idFetcher;

    /** @var PropertyAccessorInterface */
    protected $accessor;

    /** @var PropertyInfoExtractorInterface */
    protected $extractor;

    /** @var object[] */
    protected $lastInstaniatedByClass;

    public function setPropertyInfoExtractor(PropertyInfoExtractorInterface $extractor)
    {
        $this->extractor = $extractor;
        return $this;
    }

    public function setPropertyAccessor(PropertyAccessorInterface $accessor)
    {
        $this->accessor = $accessor;
        return $this;
    }

    public function setEntityManager(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->metadataFactory = $em->getMetadataFactory();
        return $this;
    }

    public function setIdFetcher(IdFetcher $idFetcher): self
    {
        $this->idFetcher = $idFetcher;
        return $this;
    }

    /**
     * @param array $data
     * @param $class
     * @param array $context
     * @param ReflectionClass $reflectionClass
     * @param $allowedAttributes
     * @param string|null $format
     * @return mixed|object|null
     * @throws ORMException
     */
    protected function instantiateObject(
        array &$data,
        $class,
        array &$context,
        ReflectionClass $reflectionClass,
        $allowedAttributes,
        string $format = null
    ) {
        // TODO: Chain of responsibility?
        $object = $this->fetchSingleValueNormalizableObject($class, $data)
            ?: $this->fetchIdentifiedObject($class, $data)
            ?: $this->fetchUniqueConstraintObject($class, $data);

        if ($object === null) {
            $object = parent::instantiateObject($data, $class, $context, $reflectionClass, $allowedAttributes, $format);
        }

        $this->addLastInstaniated($class, $object);

        return $object;
    }

    protected function setAttributeValue($object, $attribute, $value, $format = null, array $context = [])
    {
        if ($attribute === 0) {
            return;
        }

        parent::setAttributeValue(
            $object,
            $attribute,
            $value,
            $format,
            $context
        );
    }

    protected function fetchSingleValueNormalizableObject($class, array $data)
    {
        if (!$this->em || empty($data[0])) {
            return null;
        }

        return $this->fetchObject($class, $data[0]);
    }

    protected function fetchIdentifiedObject($class, array $data)
    {
        if (!($idField = $this->idFetcher->getIdField($class))) {
            return null;
        }

        if (empty($data[$idField])) {
            return null;
        }

        return $this->fetchObject($class, $data[$idField]);
    }

    /**
     * @param $class
     * @param array $data
     * @return object|null
     * @throws ORMException
     * @throws Exception
     */
    protected function fetchUniqueConstraintObject($class, array $data)
    {
        $metadata = $this->getMetadataFor($class);
        if (!$metadata instanceof ClassMetadata) {
            return null;
        }

        if (empty($metadata->table['uniqueConstraints'])) {
            return null;
        }

        foreach ($metadata->table['uniqueConstraints'] as $uniqueConstraint) {
            $criteria = [];
            foreach ($uniqueConstraint['columns'] as $column) {
                $column = trim($column, '`');

                $isReference = false;
                if (preg_match('~([^_]+)_~', $column, $m)) {
                    $isReference = true;
                    [, $propertyName] = $m;
                } else {
                    $propertyName = $column;
                }

                $types = $this->extractor->getTypes($class, $propertyName);

                $propertyClassName = null;
                foreach ($types as $type) {
                    if ($propertyClassName = $type->getClassName()) {
                        break;
                    }
                }

                $propertyValue = null;

                if ($isReference) {
                    // In some cases we can't get reference from input because it references parent object of structure
                    if (empty($data[$propertyName])) {
                        $propertyValue = $this->getLastInstaniated($propertyClassName);
                        if (!$propertyValue) {
                            throw new Exception("Can't find value for property `{$propertyName}` for class `{$class}`");
                        }
                    } else {
                        $propertyValue = $this->em->getReference($propertyClassName, $data[$propertyName]);
                    }
                } else {
                    $propertyValue = $data[$column];
                    if (is_a($propertyClassName, DateTimeInterface::class, true)) {
                        $propertyValue = new $propertyClassName($propertyValue);
                    }
                }

                if (!isset($propertyName, $propertyValue)) {
                    throw new Exception("Can't find property `{$column}` for class `{$class}`");
                }

                $criteria[$propertyName] = $propertyValue;
            }

            $object = $this->em->getRepository($class)->findOneBy($criteria);
            if (!$object) {
                return null;
            }

            $object = $this->getFullOrNullObject($object);
            if ($object) {
                return $object;
            }
        }

        return null;
    }

    protected function getMetadataFor(string $class)
    {
        try {
            return $this->metadataFactory->getMetadataFor($class);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (
            OrmMappingException|PersistenceMappingException $e
        ) {
            return null;
        }
    }

    protected function fetchObject(string $class, $id)
    {
        $object = $this->em->find($class, $id);
        if (!$object) {
            return null;
        }

        return $this->getFullOrNullObject($object);
    }

    protected function getFullOrNullObject(object $object)
    {
        try {
            $this->em->initializeObject($object);
            return $object;
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (EntityNotFoundException $e) {
            return null;
        }
    }

    protected function addLastInstaniated($class, object $object)
    {
        $this->lastInstaniatedByClass[$class] = $object;
        return $this;
    }

    protected function getLastInstaniated($class)
    {
        return $this->lastInstaniatedByClass[$class] ?? null;
    }
}
