<?php

namespace Icinga\Module\Director\DirectorObject\Automation;

use Icinga\Module\Director\Objects\DirectorDatafield;
use gipfl\Json\JsonString;
use Icinga\Module\Director\Data\Exporter;
use Icinga\Module\Director\Data\ObjectImporter;
use Icinga\Module\Director\Db;
use Ramsey\Uuid\UuidInterface;
use stdClass;

class BasketDiff
{
    /** @var Db */
    protected $db;
    /** @var ObjectImporter */
    protected $importer;
    /** @var Exporter */
    protected $exporter;
    /** @var BasketSnapshot */
    protected $snapshot;
    /** @var ?stdClass */
    protected $objects = null;
    /** @var BasketSnapshotFieldResolver */
    protected $fieldResolver;

    public function __construct(BasketSnapshot $snapshot, Db $db)
    {
        $this->db = $db;
        $this->importer = new ObjectImporter($db);
        $this->exporter = new Exporter($db);
        $this->snapshot = $snapshot;
    }

    public function hasChangedFor(string $type, string $key, ?UuidInterface $uuid = null): bool
    {
        return $this->getCurrentString($type, $key, $uuid) !== $this->getBasketString($type, $key);
    }

    public function getCurrentString(string $type, string $key, ?UuidInterface $uuid = null): string
    {
        $current = $this->getCurrent($type, $key, $uuid);
        // if (isset($current->fields)) {
        //     unset($current->fields);
        // }
        return $current ? JsonString::encode($current, JSON_PRETTY_PRINT) : '';
    }

    public function getBasketString(string $type, string $key): string
    {
        $object = $this->getBasket($type, $key);
        // if (isset($object->fields)) {
        //     unset($object->fields);
        // }
        return JsonString::encode($object, JSON_PRETTY_PRINT);
    }

    protected function getFieldResolver(): BasketSnapshotFieldResolver
    {
        if ($this->fieldResolver === null) {
            $this->fieldResolver = new BasketSnapshotFieldResolver($this->getBasketObjects(), $this->db);
        }

        return $this->fieldResolver;
    }

    public function getCurrent(string $type, string $key, ?UuidInterface $uuid = null): ?object
    {
        if ($uuid && $current = BasketSnapshot::instanceByUuid($type, $uuid, $this->db)) {
            $exported = $this->exporter->export($current);
            $this->getFieldResolver()->tweakTargetIds($exported);
        } elseif ($current = BasketSnapshot::instanceByIdentifier($type, $key, $this->db)) {
            $exported = $this->exporter->export($current);
            $this->getFieldResolver()->tweakTargetIds($exported);
        } else {
            $exported = null;
        }
        CompareBasketObject::normalize($exported);

        return $exported;
    }

    protected function getBasket($type, $key): stdClass
    {
        $object = $this->getBasketObject($type, $key);
        if ($type === 'Datafield') {
            $import = DirectorDatafield::import($object, $this->db);
            $reExport = $import->export();
        } else {
            $fields = $object->fields ?? null;
            $import = $this->importer->import(BasketSnapshot::getClassForType($type), $object);
            $reExport = $this->exporter->export(
                $import
            );
            if ($fields === null) {
                unset($reExport->fields);
            } else {
                $reExport->fields = $fields;
            }
        }

        CompareBasketObject::normalize($reExport);
        return $reExport;
    }

    public function hasCurrentInstance(string $type, string $key, ?UuidInterface $uuid = null): bool
    {
        return $this->getCurrentInstance($type, $key, $uuid) !== null;
    }

    public function getCurrentInstance(string $type, string $key, ?UuidInterface $uuid = null)
    {
        if ($uuid && $instance = BasketSnapshot::instanceByUuid($type, $uuid, $this->db)) {
            return $instance;
        } else {
            return BasketSnapshot::instanceByIdentifier($type, $key, $this->db);
        }
    }

    public function getBasketObjects(): stdClass
    {
        if ($this->objects === null) {
            $this->objects = JsonString::decode($this->snapshot->getJsonDump());
        }

        return $this->objects;
    }

    public function getBasketObject(string $type, string $key): stdClass
    {
        return $this->getBasketObjects()->$type->$key;
    }
}
