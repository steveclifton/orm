<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Locking;

use DateTime;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\DiscriminatorColumn;
use Doctrine\ORM\Mapping\DiscriminatorMap;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\InheritanceType;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\Version;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Tests\OrmFunctionalTestCase;

use function date;
use function strtotime;

class OptimisticTest extends OrmFunctionalTestCase
{
    /** @var Connection */
    private $_conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->_conn = $this->_em->getConnection();
    }

    private function createSchema(): void
    {
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(OptimisticJoinedParent::class),
            $this->_em->getClassMetadata(OptimisticJoinedChild::class),
            $this->_em->getClassMetadata(OptimisticStandard::class),
            $this->_em->getClassMetadata(OptimisticTimestamp::class),
        ]);
    }

    private function dropSchema(): void
    {
        $this->_schemaTool->dropSchema([
            $this->_em->getClassMetadata(OptimisticJoinedParent::class),
            $this->_em->getClassMetadata(OptimisticJoinedChild::class),
            $this->_em->getClassMetadata(OptimisticStandard::class),
            $this->_em->getClassMetadata(OptimisticTimestamp::class),
        ]);
    }

    public function testJoinedChildInsertSetsInitialVersionValue(): OptimisticJoinedChild
    {
        $this->createSchema();
        $test = new OptimisticJoinedChild();

        $test->name     = 'child';
        $test->whatever = 'whatever';

        $this->_em->persist($test);
        $this->_em->flush();

        self::assertEquals(1, $test->version);

        return $test;
    }

    /**
     * @depends testJoinedChildInsertSetsInitialVersionValue
     */
    public function testJoinedChildFailureThrowsException(OptimisticJoinedChild $child): void
    {
        $q = $this->_em->createQuery('SELECT t FROM Doctrine\Tests\ORM\Functional\Locking\OptimisticJoinedChild t WHERE t.id = :id');

        $q->setParameter('id', $child->id);

        $test = $q->getSingleResult();

        // Manually update/increment the version so we can try and save the same
        // $test and make sure the exception is thrown saying the record was
        // changed or updated since you read it
        $this->_conn->executeQuery('UPDATE optimistic_joined_parent SET version = ? WHERE id = ?', [2, $test->id]);

        // Now lets change a property and try and save it again
        $test->whatever = 'ok';

        try {
            $this->_em->flush();
        } catch (OptimisticLockException $e) {
            self::assertSame($test, $e->getEntity());
        }

        $this->dropSchema();
    }

    public function testJoinedParentInsertSetsInitialVersionValue(): OptimisticJoinedParent
    {
        $this->createSchema();
        $test = new OptimisticJoinedParent();

        $test->name = 'parent';

        $this->_em->persist($test);
        $this->_em->flush();

        self::assertEquals(1, $test->version);

        return $test;
    }

    /**
     * @depends testJoinedParentInsertSetsInitialVersionValue
     */
    public function testJoinedParentFailureThrowsException(OptimisticJoinedParent $parent): void
    {
        $q = $this->_em->createQuery('SELECT t FROM Doctrine\Tests\ORM\Functional\Locking\OptimisticJoinedParent t WHERE t.id = :id');

        $q->setParameter('id', $parent->id);

        $test = $q->getSingleResult();

        // Manually update/increment the version so we can try and save the same
        // $test and make sure the exception is thrown saying the record was
        // changed or updated since you read it
        $this->_conn->executeQuery('UPDATE optimistic_joined_parent SET version = ? WHERE id = ?', [2, $test->id]);

        // Now lets change a property and try and save it again
        $test->name = 'WHATT???';

        try {
            $this->_em->flush();
        } catch (OptimisticLockException $e) {
            self::assertSame($test, $e->getEntity());
        }

        $this->dropSchema();
    }

    public function testMultipleFlushesDoIncrementalUpdates(): void
    {
        $this->createSchema();

        $test = new OptimisticStandard();

        for ($i = 0; $i < 5; $i++) {
            $test->name = 'test' . $i;

            $this->_em->persist($test);
            $this->_em->flush();

            self::assertIsInt($test->getVersion());
            self::assertEquals($i + 1, $test->getVersion());
        }

        $this->dropSchema();
    }

    public function testStandardInsertSetsInitialVersionValue(): OptimisticStandard
    {
        $this->createSchema();

        $test = new OptimisticStandard();

        $test->name = 'test';

        $this->_em->persist($test);
        $this->_em->flush();

        self::assertIsInt($test->getVersion());
        self::assertEquals(1, $test->getVersion());

        return $test;
    }

    /**
     * @depends testStandardInsertSetsInitialVersionValue
     */
    public function testStandardFailureThrowsException(OptimisticStandard $entity): void
    {
        $q = $this->_em->createQuery('SELECT t FROM Doctrine\Tests\ORM\Functional\Locking\OptimisticStandard t WHERE t.id = :id');

        $q->setParameter('id', $entity->id);

        $test = $q->getSingleResult();

        // Manually update/increment the version so we can try and save the same
        // $test and make sure the exception is thrown saying the record was
        // changed or updated since you read it
        $this->_conn->executeQuery('UPDATE optimistic_standard SET version = ? WHERE id = ?', [2, $test->id]);

        // Now lets change a property and try and save it again
        $test->name = 'WHATT???';

        try {
            $this->_em->flush();
        } catch (OptimisticLockException $e) {
            self::assertSame($test, $e->getEntity());
        }

        $this->dropSchema();
    }

    public function testLockWorksWithProxy(): void
    {
        $this->createSchema();
        $test       = new OptimisticStandard();
        $test->name = 'test';

        $this->_em->persist($test);
        $this->_em->flush();
        $this->_em->clear();

        $proxy = $this->_em->getReference(OptimisticStandard::class, $test->id);

        $this->_em->lock($proxy, LockMode::OPTIMISTIC, 1);

        $this->addToAssertionCount(1);
        $this->dropSchema();
    }

    public function testOptimisticTimestampSetsDefaultValue(): OptimisticTimestamp
    {
        $this->createSchema();
        $test = new OptimisticTimestamp();

        $test->name = 'Testing';

        self::assertNull($test->version, 'Pre-Condition');

        $this->_em->persist($test);
        $this->_em->flush();

        self::assertInstanceOf('DateTime', $test->version);

        return $test;
    }

    /**
     * @depends testOptimisticTimestampSetsDefaultValue
     */
    public function testOptimisticTimestampFailureThrowsException(OptimisticTimestamp $entity): void
    {
        $q = $this->_em->createQuery('SELECT t FROM Doctrine\Tests\ORM\Functional\Locking\OptimisticTimestamp t WHERE t.id = :id');

        $q->setParameter('id', $entity->id);

        $test = $q->getSingleResult();

        self::assertInstanceOf('DateTime', $test->version);

        // Manually increment the version datetime column
        $format = $this->_em->getConnection()->getDatabasePlatform()->getDateTimeFormatString();

        $this->_conn->executeQuery('UPDATE optimistic_timestamp SET version = ? WHERE id = ?', [date($format, strtotime($test->version->format($format)) + 3600), $test->id]);

        // Try and update the record and it should throw an exception
        $caughtException = null;
        $test->name      = 'Testing again';

        try {
            $this->_em->flush();
        } catch (OptimisticLockException $e) {
            $caughtException = $e;
        }

        self::assertNotNull($caughtException, 'No OptimisticLockingException was thrown');
        self::assertSame($test, $caughtException->getEntity());
    }

    /**
     * @depends testOptimisticTimestampSetsDefaultValue
     */
    public function testOptimisticTimestampLockFailureThrowsException(OptimisticTimestamp $entity): void
    {
        $q = $this->_em->createQuery('SELECT t FROM Doctrine\Tests\ORM\Functional\Locking\OptimisticTimestamp t WHERE t.id = :id');

        $q->setParameter('id', $entity->id);

        $test = $q->getSingleResult();

        self::assertInstanceOf('DateTime', $test->version);

        // Try to lock the record with an older timestamp and it should throw an exception
        $caughtException = null;

        try {
            $expectedVersionExpired = DateTime::createFromFormat(
                'U',
                (string) ($test->version->getTimestamp() - 3600)
            );

            $this->_em->lock($test, LockMode::OPTIMISTIC, $expectedVersionExpired);
        } catch (OptimisticLockException $e) {
            $caughtException = $e;
        }

        self::assertNotNull($caughtException, 'No OptimisticLockingException was thrown');
        self::assertSame($test, $caughtException->getEntity());

        $this->dropSchema();
    }
}

/**
 * @Entity
 * @Table(name="optimistic_joined_parent")
 * @InheritanceType("JOINED")
 * @DiscriminatorColumn(name="discr", type="string")
 * @DiscriminatorMap({"parent" = "OptimisticJoinedParent", "child" = "OptimisticJoinedChild"})
 */
class OptimisticJoinedParent
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $name;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    public $version;
}


/**
 * @Entity
 * @Table(name="optimistic_joined_child")
 */
class OptimisticJoinedChild extends OptimisticJoinedParent
{
    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $whatever;
}

/**
 * @Entity
 * @Table(name="optimistic_standard")
 */
class OptimisticStandard
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $name;

    /**
     * @var int
     * @Version
     * @Column(type="integer")
     */
    private $version;

    public function getVersion(): int
    {
        return $this->version;
    }
}

/**
 * @Entity
 * @Table(name="optimistic_timestamp")
 */
class OptimisticTimestamp
{
    /**
     * @var int
     * @Id
     * @Column(type="integer")
     * @GeneratedValue(strategy="AUTO")
     */
    public $id;

    /**
     * @var string
     * @Column(type="string", length=255)
     */
    public $name;

    /**
     * @var DateTime
     * @Version
     * @Column(type="datetime")
     */
    public $version;
}
