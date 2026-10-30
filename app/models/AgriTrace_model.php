<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AgriTrace_model extends Model
{
    private $resources = [
        'farms' => [
            'table' => 'farms',
            'primary_key' => 'farm_id',
            'fields' => ['farmer_id', 'farm_name', 'farm_address', 'province', 'municipality', 'barangay', 'latitude', 'longitude', 'farm_size', 'farm_size_unit']
        ],
        'products' => [
            'table' => 'products',
            'primary_key' => 'product_id',
            'fields' => ['product_name', 'category', 'description', 'unit_of_measure']
        ],
        'batches' => [
            'table' => 'product_batches',
            'primary_key' => 'batch_id',
            'fields' => ['batch_code', 'product_id', 'farm_id', 'quantity', 'unit', 'harvest_date', 'product_image', 'current_status']
        ],
        'harvests' => [
            'table' => 'harvest_records',
            'primary_key' => 'harvest_id',
            'fields' => ['batch_id', 'farmer_id', 'harvest_date', 'quantity_harvested', 'quality_grade', 'notes']
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

    public function findResource($resource, $id)
    {
        $definition = $this->resources[$resource];
        return $this->db->table($definition['table'])
            ->where($definition['primary_key'], $id)
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
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
}
