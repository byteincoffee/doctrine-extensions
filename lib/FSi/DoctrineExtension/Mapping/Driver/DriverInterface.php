<?php

/**
 * (c) Fabryka Stron Internetowych sp. z o.o <info@fsi.pl>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FSi\DoctrineExtension\Mapping\Driver;

use Doctrine\Common\Persistence\Mapping\ClassMetadataFactory;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use FSi\Component\Metadata\Driver\DriverInterface as BaseDriverInterface;
use FSi\Component\Metadata\ClassMetadataInterface;

interface DriverInterface extends BaseDriverInterface
{
    /**
     * Set metadata factory from the underlying ORM or ODM
     *
     * @param ClassMetadataFactory $metadataFactory
     * @return void
     */
    public function setBaseMetadataFactory(ClassMetadataFactory $metadataFactory);

    /**
     * Get associated metadata factory for underlying ORM/ODM
     *
     * @return \Doctrine\Common\Persistence\Mapping\ClassMetadataFactory
     */
    public function getBaseMetadataFactory();
}
