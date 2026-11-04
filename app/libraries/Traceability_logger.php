<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Writes batch history for harvest, shipment, and batch status changes.
 */
class Traceability_logger
{
    private $db;

    public function __construct()
    {
        $lava = lava_instance();
        $lava->call->database();
        $this->db = $lava->db;
    }

    public function record_change($resource, array $before, array $after, $userId)
    {
        $batchId = 0;
        $status = 'harvested';
        $location = null;
        $remarks = '';
        $shouldLog = false;

        if ($resource === 'batches') {
            $batchId = (int) ($after['batch_id'] ?? 0);
            $status = (string) ($after['current_status'] ?? 'harvested');
            $shouldLog = !$before || ($before['current_status'] ?? null) !== ($after['current_status'] ?? null);
            $remarks = $before ? 'Batch status changed to ' . $status : 'Batch created';
        } elseif ($resource === 'harvests') {
            $batchId = (int) ($after['batch_id'] ?? 0);
            $status = 'harvested';
            $shouldLog = !$before
                || ($before['approval_status'] ?? null) !== ($after['approval_status'] ?? null)
                || ($before['harvest_date'] ?? null) !== ($after['harvest_date'] ?? null)
                || ($before['quality_grade'] ?? null) !== ($after['quality_grade'] ?? null)
                || ($before['notes'] ?? null) !== ($after['notes'] ?? null);
            $remarks = 'Harvest record ' . ($before ? 'updated' : 'created');
            if (!empty($after['quality_grade'])) {
                $remarks .= '; quality grade ' . $after['quality_grade'];
            }
            if (!empty($after['approval_status'])) {
                $remarks .= '; approval ' . $after['approval_status'];
            }
        } elseif ($resource === 'shipments') {
            $batchId = (int) ($after['batch_id'] ?? 0);
            $shipmentStatus = (string) ($after['shipment_status'] ?? 'pending');
            $shouldLog = !$before
                || ($before['shipment_status'] ?? null) !== ($after['shipment_status'] ?? null)
                || ($before['dispatch_time'] ?? null) !== ($after['dispatch_time'] ?? null)
                || ($before['delivery_time'] ?? null) !== ($after['delivery_time'] ?? null);
            if ($shipmentStatus === 'in_transit') {
                $status = 'in_transit';
            } elseif ($shipmentStatus === 'delivered') {
                $status = 'received';
            } else {
                $batch = $this->db->table('product_batches')
                    ->select('current_status')
                    ->where('batch_id', $batchId)
                    ->limit(1)
                    ->get(PDO::FETCH_ASSOC);
                $status = $batch ? $batch['current_status'] : 'harvested';
            }
            $location = $after['delivery_time'] ? ($after['destination'] ?? null) : ($after['origin'] ?? null);
            $remarks = 'Shipment ' . ($before ? 'updated' : 'created') . '; status ' . $shipmentStatus;
            if (!empty($after['dispatch_time'])) {
                $remarks .= '; dispatched ' . $after['dispatch_time'];
            }
            if (!empty($after['delivery_time'])) {
                $remarks .= '; delivered ' . $after['delivery_time'];
            }
        }

        if (!$shouldLog || $batchId < 1) {
            return;
        }
        $validStatuses = ['harvested', 'collected', 'in_transit', 'received', 'available_at_market', 'sold', 'rejected'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException('Cannot log an unsupported batch status.');
        }
        $this->db->table('traceability_logs')->insert([
            'batch_id' => $batchId,
            'updated_by' => (int) $userId,
            'status' => $status,
            'location_name' => $location,
            'remarks' => $remarks,
        ]);
    }
}
