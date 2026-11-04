<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AgriTrace_model extends Model
{
    private $resources = [
        'farms' => [
            'table' => 'farms',
            'primary_key' => 'farm_id',
            'fields' => ['farmer_id', 'farm_name', 'farm_address', 'province', 'municipality', 'barangay', 'latitude', 'longitude', 'farm_size', 'farm_size_unit', 'farm_description']
        ],
        'products' => [
            'table' => 'products',
            'primary_key' => 'product_id',
            'fields' => ['product_name', 'category', 'description', 'unit_of_measure']
        ],
        'batches' => [
            'table' => 'product_batches',
            'primary_key' => 'batch_id',
            'fields' => ['batch_code', 'product_id', 'farm_id', 'quantity', 'unit', 'unit_price', 'selling_unit', 'harvest_date', 'expiration_date', 'harvest_reference', 'product_image', 'current_status']
        ],
        'harvests' => [
            'table' => 'harvest_records',
            'primary_key' => 'harvest_id',
            'fields' => ['batch_id', 'farmer_id', 'harvest_date', 'quantity_harvested', 'quality_grade', 'approval_status', 'harvest_reference', 'product_id', 'unit_of_measure', 'description', 'harvest_status', 'notes']
        ],
        'shipments' => [
            'table' => 'shipments',
            'primary_key' => 'shipment_id',
            'fields' => ['batch_id', 'transporter_id', 'vehicle_number', 'origin', 'destination', 'dispatch_time', 'delivery_time', 'shipment_status', 'remarks']
        ],
        'markets' => [
            'table' => 'markets',
            'primary_key' => 'market_id',
            'fields' => ['market_name', 'manager_id', 'address', 'province', 'municipality', 'contact_number', 'latitude', 'longitude']
        ],
    ];

    public function hasResource($resource)
    {
        return isset($this->resources[$resource]);
    }

    public function listResource($resource, $limit = 100)
    {
        $definition = $this->resources[$resource];
        $limit = max(1, min((int) $limit, 100));

        return $this->db->table($definition['table'])
            ->order_by($definition['primary_key'], 'DESC')
            ->limit($limit)
            ->get_all(PDO::FETCH_ASSOC);
    }

    public function listResourceForFarmer($resource, $farmerId, $limit = 100)
    {
        $limit = max(1, min((int) $limit, 100));
        if ($resource === 'products') {
            return $this->listResource($resource, $limit);
        }
        if (!isset($this->resources[$resource]) || $resource === 'markets') {
            return [];
        }
        $queries = [
            'farms' => ['SELECT * FROM farms WHERE farmer_id = ? ORDER BY farm_id DESC LIMIT ' . $limit, [$farmerId]],
            'batches' => ['SELECT pb.*, q.qr_image_path AS qr_url FROM product_batches pb INNER JOIN farms f ON f.farm_id = pb.farm_id LEFT JOIN qr_codes q ON q.batch_id = pb.batch_id AND q.is_active = 1 WHERE f.farmer_id = ? ORDER BY pb.batch_id DESC LIMIT ' . $limit, [$farmerId]],
            'harvests' => ['SELECT hr.* FROM harvest_records hr INNER JOIN product_batches pb ON pb.batch_id = hr.batch_id INNER JOIN farms f ON f.farm_id = pb.farm_id WHERE f.farmer_id = ? ORDER BY hr.harvest_id DESC LIMIT ' . $limit, [$farmerId]],
            'shipments' => ['SELECT sh.* FROM shipments sh INNER JOIN product_batches pb ON pb.batch_id = sh.batch_id INNER JOIN farms f ON f.farm_id = pb.farm_id WHERE f.farmer_id = ? ORDER BY sh.shipment_id DESC LIMIT ' . $limit, [$farmerId]],
        ];
        return $this->db->raw($queries[$resource][0], $queries[$resource][1])->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findResource($resource, $id)
    {
        $definition = $this->resources[$resource];
        return $this->db->table($definition['table'])
            ->where($definition['primary_key'], $id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function findResourceForFarmer($resource, $id, $farmerId)
    {
        if ($resource === 'products') {
            return $this->findResource($resource, $id);
        }
        if (!isset($this->resources[$resource]) || $resource === 'markets') {
            return null;
        }
        $queries = [
            'farms' => ['SELECT * FROM farms WHERE farm_id = ? AND farmer_id = ? LIMIT 1', [$id, $farmerId]],
            'batches' => ['SELECT pb.* FROM product_batches pb INNER JOIN farms f ON f.farm_id = pb.farm_id WHERE pb.batch_id = ? AND f.farmer_id = ? LIMIT 1', [$id, $farmerId]],
            'harvests' => ['SELECT hr.* FROM harvest_records hr INNER JOIN product_batches pb ON pb.batch_id = hr.batch_id INNER JOIN farms f ON f.farm_id = pb.farm_id WHERE hr.harvest_id = ? AND f.farmer_id = ? LIMIT 1', [$id, $farmerId]],
            'shipments' => ['SELECT sh.* FROM shipments sh INNER JOIN product_batches pb ON pb.batch_id = sh.batch_id INNER JOIN farms f ON f.farm_id = pb.farm_id WHERE sh.shipment_id = ? AND f.farmer_id = ? LIMIT 1', [$id, $farmerId]],
        ];
        $row = $this->db->raw($queries[$resource][0], $queries[$resource][1])->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function farmerTraceability($farmerId)
    {
        $transporters = $this->db->table('users')
            ->select('id, username, first_name, last_name')
            ->where(['role' => 'transporter', 'is_active' => 1])
            ->order_by('first_name', 'ASC')
            ->order_by('last_name', 'ASC')
            ->limit(100)
            ->get_all(PDO::FETCH_ASSOC);
        return [
            'farms' => $this->listResourceForFarmer('farms', $farmerId),
            'products' => $this->listResource('products', 100),
            'batches' => $this->listResourceForFarmer('batches', $farmerId),
            'harvests' => $this->listResourceForFarmer('harvests', $farmerId),
            'shipments' => $this->listResourceForFarmer('shipments', $farmerId),
            'transporters' => $transporters,
        ];
    }

    public function farmBelongsToFarmer($farmId, $farmerId)
    {
        return (bool) $this->db->table('farms')
            ->select('farm_id')
            ->where('farm_id', $farmId)
            ->where('farmer_id', $farmerId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function batchBelongsToFarmer($batchId, $farmerId)
    {
        return (bool) $this->db->table('product_batches')
            ->select('product_batches.batch_id')
            ->inner_join('farms', 'farms.farm_id = product_batches.farm_id')
            ->where('product_batches.batch_id', $batchId)
            ->where('farms.farmer_id', $farmerId)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }

    public function nextBatchCode($productId)
    {
        $prefix = 'BATCH-' . date('Ymd') . '-';
        $last = $this->db->raw(
            'SELECT batch_code FROM product_batches WHERE batch_code LIKE ? ORDER BY batch_id DESC LIMIT 1',
            [$prefix . '%']
        )->fetch(PDO::FETCH_ASSOC);
        $sequence = $last && preg_match('/-(\d+)$/', $last['batch_code'], $match) ? ((int) $match[1]) + 1 : 1;
        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function createResource($resource, array $input)
    {
        $definition = $this->resources[$resource];
        $fields = [];
        $values = [];

        foreach ($definition['fields'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = $field;
                $values[] = $input[$field];
            }
        }

        if (!$fields) {
            return null;
        }

        $record = array_combine($fields, $values);
        return $this->db->table($definition['table'])->insert($record);
    }

    public function createHarvestForBatch($batchId, $farmerId, array $input)
    {
        return $this->db->table('harvest_records')->insert([
            'batch_id' => (int) $batchId,
            'farmer_id' => (int) $farmerId,
            'harvest_date' => $input['harvest_date'],
            'quantity_harvested' => $input['quantity'],
            'quality_grade' => $input['quality_grade'] ?? null,
            'approval_status' => 'approved',
            'harvest_reference' => $input['harvest_reference'] ?? null,
            'product_id' => $input['product_id'],
            'unit_of_measure' => $input['unit'],
            'harvest_status' => 'available',
            'description' => $input['harvest_description'] ?? null,
            'notes' => $input['harvest_notes'] ?? null,
        ]);
    }

    public function updateResource($resource, $id, array $input)
    {
        $definition = $this->resources[$resource];
        $updates = [];

        foreach ($definition['fields'] as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }

        if (!$updates) {
            return false;
        }

        return (bool) $this->db->table($definition['table'])
            ->where($definition['primary_key'], $id)
            ->update($updates);
    }

    public function deleteResource($resource, $id)
    {
        $definition = $this->resources[$resource];
        return $this->db->table($definition['table'])
            ->where($definition['primary_key'], $id)
            ->delete();
    }

    public function deleteBatchSafely($batchId, $farmerId = null)
    {
        $batchId = (int) $batchId;
        $orderReference = $this->db->raw(
            'SELECT item_id FROM order_items WHERE batch_id = ? LIMIT 1',
            [$batchId]
        )->fetch(PDO::FETCH_ASSOC);
        $allocationReference = $this->db->raw(
            'SELECT allocation_id FROM batch_allocations WHERE batch_id = ? LIMIT 1',
            [$batchId]
        )->fetch(PDO::FETCH_ASSOC);
        $weatherReference = $this->db->raw(
            'SELECT weather_id FROM weather_records
             WHERE harvest_id IN (SELECT harvest_id FROM harvest_records WHERE batch_id = ?)
             LIMIT 1',
            [$batchId]
        )->fetch(PDO::FETCH_ASSOC);
        if ($orderReference || $allocationReference || $weatherReference) {
            return false;
        }

        $this->db->raw(
            "DELETE FROM community_posts
             WHERE post_type = 'product'
               AND listing_id IN (SELECT listing_id FROM marketplace_listings WHERE batch_id = ?)",
            [$batchId]
        );
        $this->db->raw(
            'UPDATE marketplace_listings SET batch_id = NULL, is_available = 0, stock = 0 WHERE batch_id = ?',
            [$batchId]
        );
        $this->db->raw('DELETE FROM qr_codes WHERE batch_id = ?', [$batchId]);
        $this->db->raw('DELETE FROM shipments WHERE batch_id = ?', [$batchId]);
        $this->db->raw('DELETE FROM harvest_records WHERE batch_id = ?', [$batchId]);
        $this->db->raw('DELETE FROM traceability_logs WHERE batch_id = ?', [$batchId]);
        $this->db->raw('DELETE FROM inventory_movements WHERE batch_id = ?', [$batchId]);
        if ($farmerId === null) {
            $deleted = $this->db->raw('DELETE FROM product_batches WHERE batch_id = ?', [$batchId]);
        } else {
            $deleted = $this->db->raw(
                'DELETE pb FROM product_batches pb
                 INNER JOIN farms f ON f.farm_id = pb.farm_id
                 WHERE pb.batch_id = ? AND f.farmer_id = ?',
                [$batchId, (int) $farmerId]
            );
        }
        return $deleted->rowCount() > 0;
    }

    public function deleteHarvestSafely($harvestId)
    {
        $reference = $this->db->raw(
            'SELECT allocation_id FROM batch_allocations WHERE harvest_id = ? LIMIT 1',
            [(int) $harvestId]
        )->fetch(PDO::FETCH_ASSOC);
        $weatherReference = $this->db->raw(
            'SELECT weather_id FROM weather_records WHERE harvest_id = ? LIMIT 1',
            [(int) $harvestId]
        )->fetch(PDO::FETCH_ASSOC);
        if ($reference || $weatherReference) {
            return false;
        }
        return $this->deleteResource('harvests', (int) $harvestId);
    }

    public function saveBatchQr($batchId, $token, $imageUrl)
    {
        return $this->db->table('qr_codes')->insert([
            'batch_id' => $batchId,
            'qr_token' => $token,
            'qr_image_path' => $imageUrl,
            'is_active' => 1,
        ]);
    }

    public function batchCodeExists($batchCode)
    {
        return (bool) $this->db->table('product_batches')
            ->select('batch_id')
            ->where('batch_code', $batchCode)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
    }
}
