<?php

/**
 * (c) FSi sp. z o.o. <info@fsi.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FSi\DoctrineExtensions\Translatable;

use Doctrine\Common\EventArgs;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\Event\OnClearEventArgs;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\PropertyAccess\PropertyAccess;
use FSi\Component\Metadata\MetadataFactory;
use FSi\Component\Metadata\ClassMetadataInterface;
use FSi\Component\PropertyObserver\PropertyObserver;
use FSi\DoctrineExtensions\Mapping\MappedEventSubscriber;
use FSi\DoctrineExtensions\Translatable\Exception;
use FSi\DoctrineExtensions\Translatable\Mapping\ClassMetadata as TranslatableClassMetadata;

class TranslatableListener extends MappedEventSubscriber
{
    /**
     * Current locale of the listener
     *
     * @var mixed
     */
    private $_currentLocale;

    /**
     * Default locale of the listener used when there is no translation in current locale
     *
     * @var mixed
     */
    private $_defaultLocale;

    /**
     * Array of PropertyObserver instances for each ObjectManager's context
     *
     * @var \FSi\Component\PropertyObserver\PropertyObserver[]
     */
    private $_propertyObservers = array();

    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'postLoad',
            'postHydrate',
            'preFlush',
            'onClear'
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function validateExtendedMetadata(ClassMetadata $baseClassMetadata, ClassMetadataInterface $extendedClassMetadata)
    {
        if ($extendedClassMetadata->hasTranslatableProperties()) {
            if (!isset($extendedClassMetadata->localeProperty)) {
                throw new Exception\MappingException('Entity \'' . $baseClassMetadata->name . '\' has translatable properties so it must have property marked with @Translatable\Language annotation');
            }
            $translatableProperties = $extendedClassMetadata->getTranslatableProperties();
            foreach ($translatableProperties as $translation => $properties) {
                if (!$baseClassMetadata->hasAssociation($translation) || !$baseClassMetadata->isCollectionValuedAssociation($translation)) {
                    throw new Exception\MappingException('Field \'' . $translation . '\' in entity \'' . $baseClassMetadata->name . '\' has to be a OneToMany association');
                }
            }
        }
        if (isset($extendedClassMetadata->localeProperty)) {
            if ($extendedClassMetadata->hasTranslatableProperties() && (
                    $baseClassMetadata->hasField($extendedClassMetadata->localeProperty) ||
                    $baseClassMetadata->hasAssociation($extendedClassMetadata->localeProperty))) {
                throw new Exception\MappingException('Entity \''.$baseClassMetadata->name.'\' seems to be a translatable entity so its \'' . $extendedClassMetadata->localeProperty . '\' field must not be persistent');
            }
            else if (!$extendedClassMetadata->hasTranslatableProperties() &&
                    !$baseClassMetadata->hasField($extendedClassMetadata->localeProperty) &&
                    !$baseClassMetadata->hasAssociation($extendedClassMetadata->localeProperty)) {
                throw new Exception\MappingException('Entity \''.$baseClassMetadata->name.'\' seems to be a translation entity so its \'' . $extendedClassMetadata->localeProperty . '\' field must be persistent');
            }
        }
    }

    /**
     * Get the namespace of extension event subscriber
     * used for cache id of extensions also to know where
     * to find Mapping drivers and event adapters
     *
     * @return string
     */
    public function getNamespace()
    {
        return __NAMESPACE__;
    }

    /**
     * Returns PropertyObserver for specified ObjectManager
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $om
     * @return \FSi\Component\PropertyObserver\PropertyObserver:
     */
    protected function getPropertyObserver(ObjectManager $objectManager)
    {
        $oid = spl_object_hash($objectManager);
        if (!isset($this->_propertyObservers[$oid])) {
            $this->_propertyObservers[$oid] = new PropertyObserver();
        }
        return $this->_propertyObservers[$oid];
    }

    /**
     * Load translations fields into object properties
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param mixed $locale
     */
    public function loadTranslation(ObjectManager $objectManager, $object, $locale)
    {
        $class = get_class($object);
        $meta = $objectManager->getClassMetadata($class);
        $translationMeta = $this->getExtendedMetadata($objectManager, $class);
        return $this->loadTranslations($meta, $translationMeta, $objectManager, $object, $locale);
    }

    /**
     * Load translations fields into object properties
     *
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $meta
     * @param \FSi\DoctrineExtensions\Translatable\Mapping\ClassMetadata $translatableMeta
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param object $object
     * @param mixed $currentLocale
     */
    protected function loadTranslations(ClassMetadata $meta, TranslatableClassMetadata $translatableMeta, ObjectManager $objectManager, $object, $currentLocale)
    {
        $propertyObserver = $this->getPropertyObserver($objectManager);
        $translationFound = false;
        $translatableProperties = $translatableMeta->getTranslatableProperties();
        $localeProperty = $translatableMeta->localeProperty;
        foreach ($translatableProperties as $translation => $properties) {
            $translations = $meta->getFieldValue($object, $translation);
            // Do not try to find translation if translations association is not yet initialized i.e. during postLoad
            if (!isset($translations)) {
                continue;
            }

            $translationEntity = $meta->getAssociationTargetClass($translation);
            $translationMeta = $objectManager->getClassMetadata($translationEntity);
            $translationLanguageField = $this->getTranslationLanguageField($objectManager, $translationMeta->name);

            $currentTranslation = null;
            if (isset($currentLocale) && isset($translations)) {
                $currentTranslation = $this->findTranslation($translations, $translationMeta, $translationLanguageField, $currentLocale);
            }

            if (!isset($currentTranslation) && isset($this->_defaultLocale)) {
                $currentTranslation = $this->findTranslation($translations, $translationMeta, $translationLanguageField, $this->_defaultLocale);
                if (isset($currentTranslation)) {
                    $currentLocale = $this->_defaultLocale;
                }
            }

            if (!isset($currentTranslation)) {
                foreach ($properties as $property => $translationField) {
                    $propertyObserver->setValue($object, $property, null);
                }
                continue;
            }

            $translationFound = true;
            foreach ($properties as $property => $translationField) {
                $propertyObserver->setValue(
                    $object,
                    $property,
                    $translationMeta->getFieldValue($currentTranslation, $translationField)
                );
            }
        }

        if ($translationFound) {
            $propertyObserver->setValue($object, $localeProperty, $currentLocale);
        } else {
            $propertyObserver->setValue($object, $localeProperty, null);
        }
        return $translationFound;
    }

    /**
     * After loading the entity copy the current translation fields into non-persistent translatable properties
     *
     * @param \Doctrine\Common\EventArgs $eventArgs
     */
    public function postLoad(EventArgs $eventArgs)
    {
        $eventAdapter     = $this->getEventAdapter($eventArgs);
        $objectManager    = $eventAdapter->getObjectManager();
        $object           = $eventAdapter->getObject();
        $meta             = $objectManager->getClassMetadata(get_class($object));
        $translatableMeta = $this->getExtendedMetadata($objectManager, $meta->name);

        if ($translatableMeta->hasTranslatableProperties()) {
            $currentLocale = $this->getLocale();
            $this->loadTranslations($meta, $translatableMeta, $objectManager, $object, $currentLocale);
        }
    }

    /**
     * After loading the entity copy the current translation fields into non-persistent translatable properties
     *
     * @param \Doctrine\Common\EventArgs $eventArgs
     */
    public function postHydrate(EventArgs $eventArgs)
    {
        $this->postLoad($eventArgs);
    }

    /**
     * Helper method to insert, remove or update translations entities associated with specified object
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $meta
     * @param \FSi\DoctrineExtensions\Translatable\Mapping\ClassMetadata $translatableMeta
     * @param object $object
     */
    protected function updateTranslations(ObjectManager $objectManager, ClassMetadata $meta, TranslatableClassMetadata $translatableMeta, $object)
    {
        $localeProperty = $translatableMeta->localeProperty;
        $propertyObserver = $this->getPropertyObserver($objectManager);
        $locale = $objectLocale = PropertyAccess::createPropertyAccessor()->getValue($object, $localeProperty);
        $objectLanguageChanged = (!$propertyObserver->hasSavedValue($object, $localeProperty) && isset($objectLocale)) ||
            ($propertyObserver->hasSavedValue($object, $localeProperty) && $propertyObserver->hasValueChanged($object, $localeProperty));
        if (!isset($locale))
            $locale = $this->getLocale();

        $translatableProperties = $translatableMeta->getTranslatableProperties();
        foreach ($translatableProperties as $translation => $properties) {

            $translationEntity = $meta->getAssociationTargetClass($translation);
            $translationMeta = $objectManager->getClassMetadata($translationEntity);
            $translationLanguageField = $this->getTranslationLanguageField($objectManager, $translationMeta->name);
            $translationAssociation = $meta->getAssociationMapping($translation);

            $translations = $meta->getFieldValue($object, $translation);
            $currentTranslation = null;
            if (isset($translations)) {
                $currentTranslation = $this->findTranslation($translations, $translationMeta, $translationLanguageField, $locale);
            }

            $propertiesFound = false;
            foreach ($properties as $property => $translationField) {
                $propertyValue = PropertyAccess::createPropertyAccessor()->getValue($object, $property);
                if (isset($propertyValue)) {
                    $propertiesFound = true;
                }
                if ($objectLanguageChanged || (!$propertyObserver->hasSavedValue($object, $property) && isset($propertyValue)) ||
                    ($propertyObserver->hasSavedValue($object, $property) && $propertyObserver->hasValueChanged($object, $property))) {
                    if (isset($propertyValue)) {
                        if (!isset($currentTranslation)) {
                            $currentTranslation = new $translationEntity();
                            $translationMeta->setFieldValue($currentTranslation, $translationLanguageField, $locale);
                            $translationMeta->setFieldValue($currentTranslation, $translationAssociation['mappedBy'], $object);
                            if (isset($translationAssociation['indexBy'])) {
                                $index = $translationMeta->getFieldValue($currentTranslation, $translationAssociation['indexBy']);
                                $translations[$index] = $currentTranslation;
                            } else {
                                $translations[] = $currentTranslation;
                            }
                            $objectManager->persist($currentTranslation);
                        }
                        $translationMeta->setFieldValue($currentTranslation, $translationField, $propertyValue);
                    } else if ($currentTranslation) {
                        $translationMeta->setFieldValue($currentTranslation, $translationField, null);
                    }
                }
            }
            if ($propertiesFound && !isset($locale)) {
                throw new Exception\RuntimeException('Neither object\'s locale nor the default locale was defined for translatable properties');
            }

            if (!$propertiesFound && isset($currentTranslation) && isset($objectLocale)) {
                $objectManager->remove($currentTranslation);
                if ($translations->contains($currentTranslation)) {
                    $translations->removeElement($currentTranslation);
                }
            }

        }
    }

    /**
     * This event handler will update, insert or remove translation entities if main object's translatable properties change.
     *
     * @param \Doctrine\ORM\Event\PreFlushEventArgs $eventArgs
     * @return void
     */
    public function preFlush(PreFlushEventArgs $eventArgs)
    {
        $entityManager = $eventArgs->getEntityManager();
        /* @var $unitOfWork UnitOfWork */
        $unitOfWork    = $entityManager->getUnitOfWork();

        foreach ($unitOfWork->getScheduledEntityInsertions() as $object) {
            $class = get_class($object);
            $translatableMeta = $this->getExtendedMetadata($entityManager, $class);
            if (!$translatableMeta->hasTranslatableProperties()) {
                continue;
            }
            $meta = $entityManager->getClassMetadata($class);
            $this->updateTranslations($entityManager, $meta, $translatableMeta, $object);
        }

        foreach ($unitOfWork->getIdentityMap() as $class => $entities) {
            $translatableMeta = $this->getExtendedMetadata($entityManager, $class);
            if (!$translatableMeta->hasTranslatableProperties()) {
                continue;
            }
            $meta = $entityManager->getClassMetadata($class);
            foreach ($entities as $object) {
                if ($object instanceof \Doctrine\ORM\Proxy\Proxy) {
                    continue;
                }
                $this->updateTranslations($entityManager, $meta, $translatableMeta, $object);
            }
        }
    }

    /**
     * Clears embedded object observer for associated entity manager
     *
     * @param \Doctrine\ORM\Event\OnClearEventArgs $eventArgs
     */
    public function onClear(OnClearEventArgs $eventArgs)
    {
        if ($eventArgs->clearsAllEntities()) {
            $eventAdapter = $this->getEventAdapter($eventArgs);
            $objectManager = $eventAdapter->getObjectManager();
            $oid = spl_object_hash($objectManager);
            unset($this->_propertyObservers[$oid]);
        }
    }

    /**
     * Set the current locale
     *
     * @param mixed $locale
     * @return \FSi\DoctrineExtensions\Translatable\TranslatableListener
     */
    public function setLocale($locale)
    {
        $this->_currentLocale = $locale;
        return $this;
    }

    /**
     * Get the current locale
     *
     * @return mixed
     */
    public function getLocale()
    {
        return $this->_currentLocale;
    }

    /**
     * Set the default locale
     *
     * @param mixed $defaultLocale
     * @return \FSi\DoctrineExtensions\Translatable\TranslatableListener
     */
    public function setDefaultLocale($defaultLocale)
    {
        $this->_defaultLocale = $defaultLocale;
        return $this;
    }

    /**
     * Get the default locale
     *
     * @return mixed
     */
    public function getDefaultLocale()
    {
        return $this->_defaultLocale;
    }

    /**
     * Get current language from target entity
     *
     * @param \Doctrine\Common\Persistence\ObjectManager $objectManager
	 * @param string $targetEntity
	 *
     * @return string
     */
    private function getTranslationLanguageField(ObjectManager $objectManager, $translationEntity)
    {
        $translatableMeta = $this->getExtendedMetadata($objectManager, $translationEntity);

        if (!isset($translatableMeta->localeProperty)) {
            throw new Exception\MappingException('Entity \''.$translationEntity.'\' seems to be a translation entity so it must have field mapped as translatable locale');
        }

        return $translatableMeta->localeProperty;
    }

    /**
     * Find translation entity by specified language using filter method from ArrayCollection class
     *
     * @param \Doctrine\Common\Collections\ArrayCollection $translates
     * @param \Doctrine\Common\Persistence\Mapping\ClassMetadata $translationMeta
     * @param string $translationLocaleField
     * @param mixed $locale
     *
     * @return ArrayCollection
     */
    private function findTranslation(Collection $translations, ClassMetadata $translationMeta, $translationLocaleField, $locale)
    {
        $translations = $translations->filter(function($translation) use ($locale, $translationMeta, $translationLocaleField) {
            $translationLocale = $translationMeta->getFieldValue($translation, $translationLocaleField);
            if ($translationLocale === $locale) {
                return true;
            } else {
                return false;
            }
        });

        if (!$translations->count()) {
            return null;
        } else if ($translations->count() > 1) {
            throw new Exception\RuntimeException('Multiple translations found for one locale');
        } else {
            return $translations->first();
        }
    }
}
