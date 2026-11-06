<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Platform_model extends Model
{
    public function audit($userId, $action, $entityType, $entityId = null, $details = null)
    {
        // entity_id is an integer column; string references (file paths, codes) go into the details text instead.
        if ($entityId !== null && !is_int($entityId) && !ctype_digit((string) $entityId)) {
            $details = trim((string) $entityId . ' ' . (string) $details);
            $entityId = null;
        }
        $this->db->table('audit_logs')->insert([
            'user_id' => $userId, 'action' => $action, 'entity_type' => $entityType,
            'entity_id' => $entityId, 'details' => $details, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    public function notify($userId, $title, $message)
    {
        $this->db->table('notifications')->insert([
            'user_id' => $userId, 'title' => $title, 'message' => $message,
            'channel' => 'system', 'delivery_status' => 'sent'
        ]);
    }

    public function notifications($userId, $limit = 50)
    {
        $rows = $this->db->table('notifications')
            ->select('notification_id, title, message, created_at, delivery_status')
            ->where('user_id', $userId)->order_by('notification_id', 'DESC')->limit(max(1, min((int) $limit, 100)))
            ->get_all(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_unread'] = $row['delivery_status'] !== 'read';
            unset($row['delivery_status']);
        }
        return $rows;
    }

    public function mark_notifications_read($userId)
    {
        return $this->db->table('notifications')->where('user_id', $userId)
            ->where('delivery_status', '<>', 'read')->update(['delivery_status' => 'read']);
    }

    public function groups($userId)
    {
        $groups = $this->db->table('community_groups')->select('group_id, name, category')
            ->order_by('group_id')->get_all(PDO::FETCH_ASSOC);
        $memberships = $this->db->table('group_members')->select('group_id')
            ->select_count('user_id', 'members')->group_by('group_id')->get_all(PDO::FETCH_ASSOC);
        $joined = $this->db->table('group_members')->select('group_id')
            ->where('user_id', $userId)->get_all(PDO::FETCH_COLUMN);
        $membersByGroup = [];
        foreach ($memberships as $membership) {
            $membersByGroup[(int) $membership['group_id']] = (int) $membership['members'];
        }
        $joinedGroups = array_fill_keys(array_map('intval', $joined), true);
        foreach ($groups as &$group) {
            $groupId = (int) $group['group_id'];
            $group['members'] = $membersByGroup[$groupId] ?? 0;
            $group['joined'] = isset($joinedGroups[$groupId]);
        }
        unset($group);
        return $groups;
    }

    public function group_exists($groupId)
    {
        return (bool) $this->db->table('community_groups')->select('group_id')->where('group_id', $groupId)->limit(1)->get();
    }

    public function toggle_group($userId, $groupId)
    {
        $query = $this->db->table('group_members')->where(['group_id' => $groupId, 'user_id' => $userId]);
        $joined = (bool) $query->limit(1)->get();
        if ($joined) {
            $query->delete();
        } else {
            $this->db->table('group_members')->insert(['group_id' => $groupId, 'user_id' => $userId]);
        }
        return !$joined;
    }

    public function farmers($viewerId)
    {
        $farmerRows = $this->db->table('users')
            ->select('users.id, users.username, users.first_name, users.middle_name, users.last_name, users.role, users.profile_pic, users.is_verified, farms.farm_id, farms.farm_name, farms.municipality, farms.province')
            ->left_join('farms', 'farms.farmer_id = users.id')
            ->where(['users.role' => 'farmer', 'users.is_active' => 1])
            ->where('users.id', '<>', $viewerId)
            ->order_by('farms.farm_id', 'DESC')
            ->get_all(PDO::FETCH_ASSOC);
        $farmers = [];
        foreach ($farmerRows as $farmer) {
            $farmerId = (int) $farmer['id'];
            if (isset($farmers[$farmerId])) {
                continue;
            }
            unset($farmer['farm_id']);
            $farmers[$farmerId] = $farmer;
        }
        $farmers = array_values($farmers);
        $followerCounts = $this->db->table('user_follows')->select('followee_id')
            ->select_count('followee_id', 'followers')->group_by('followee_id')
            ->get_all(PDO::FETCH_ASSOC);
        $following = $this->db->table('user_follows')->select('followee_id')
            ->where('follower_id', $viewerId)->get_all(PDO::FETCH_COLUMN);
        $followersByUser = [];
        foreach ($followerCounts as $row) {
            $followersByUser[(int) $row['followee_id']] = (int) $row['followers'];
        }
        $followingIds = array_fill_keys(array_map('intval', $following), true);
        foreach ($farmers as &$farmer) {
            $farmerId = (int) $farmer['id'];
            $farmer['following'] = isset($followingIds[$farmerId]);
            $farmer['followers'] = $followersByUser[$farmerId] ?? 0;
        }
        unset($farmer);
        usort($farmers, function ($a, $b) {
            return $b['followers'] <=> $a['followers'] ?: $a['id'] <=> $b['id'];
        });
        return $farmers;
    }

    public function toggle_follow($followerId, $followeeId)
    {
        $query = $this->db->table('user_follows')->where(['follower_id' => $followerId, 'followee_id' => $followeeId]);
        $following = (bool) $query->limit(1)->get();
        if ($following) {
            $query->delete();
        } else {
            $this->db->table('user_follows')->insert(['follower_id' => $followerId, 'followee_id' => $followeeId]);
        }
        return !$following;
    }

    public function is_following($followerId, $followeeId)
    {
        return (bool) $this->db->table('user_follows')->select('followee_id')
            ->where(['follower_id' => $followerId, 'followee_id' => $followeeId])
            ->limit(1)->get();
    }

    public function user_exists($userId)
    {
        return (bool) $this->db->table('users')->select('id')
            ->where(['id' => $userId, 'is_active' => 1])->limit(1)->get();
    }

    public function saved_listings($userId)
    {
        return $this->db->table('saved_items')
            ->select('l.listing_id, l.seller_id, l.product_name, l.category, l.description, l.price, l.stock, l.unit, l.location, l.image_url, u.username')
            ->inner_join('marketplace_listings l', 'l.listing_id = saved_items.listing_id')
            ->inner_join('users u', 'u.id = l.seller_id')->where('saved_items.user_id', $userId)
            ->where('l.is_available', 1)->order_by('saved_items.created_at', 'DESC')->get_all(PDO::FETCH_ASSOC);
    }

    public function listing_exists($listingId)
    {
        return (bool) $this->db->table('marketplace_listings')->select('listing_id')->where('listing_id', $listingId)->limit(1)->get();
    }

    public function toggle_saved($userId, $listingId)
    {
        $query = $this->db->table('saved_items')->where(['user_id' => $userId, 'listing_id' => $listingId]);
        $saved = (bool) $query->limit(1)->get();
        if ($saved) {
            $query->delete();
        } else {
            $this->db->table('saved_items')->insert(['user_id' => $userId, 'listing_id' => $listingId]);
        }
        return !$saved;
    }

    public function contacts($userId)
    {
        $messages = $this->db->table('messages')
            ->select('message_id, sender_id, receiver_id, body, created_at, read_at')
            ->grouped(function ($db) use ($userId) {
                $db->where('sender_id', $userId)->or_where('receiver_id', $userId);
            })->order_by('message_id', 'DESC')->get_all(PDO::FETCH_ASSOC);
        $latest = [];
        foreach ($messages as $message) {
            $otherId = (int) ($message['sender_id'] == $userId ? $message['receiver_id'] : $message['sender_id']);
            if (!isset($latest[$otherId])) {
                $latest[$otherId] = [
                    'message' => $message,
                    'unread' => 0
                ];
            }
            if ((int) $message['receiver_id'] === (int) $userId && $message['read_at'] === null) {
                $latest[$otherId]['unread']++;
            }
        }
        if (!$latest) {
            return [];
        }
        $contactIds = array_keys($latest);
        $users = $this->db->table('users')->select('id, username, first_name, last_name, profile_pic')
            ->grouped(function ($db) use ($contactIds) {
                foreach ($contactIds as $index => $contactId) {
                    $index === 0 ? $db->where('id', $contactId) : $db->or_where('id', $contactId);
                }
            })->get_all(PDO::FETCH_ASSOC);
        $usersById = [];
        foreach ($users as $user) {
            $usersById[(int) $user['id']] = $user;
        }
        $contacts = [];
        foreach ($latest as $otherId => $data) {
            if (!isset($usersById[$otherId])) {
                continue;
            }
            $user = $usersById[$otherId];
            $contacts[] = [
                'user_id' => $user['id'],
                'username' => $user['username'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'profile_pic' => $user['profile_pic'],
                'last_body' => $data['message']['body'],
                'last_at' => $data['message']['created_at'],
                'unread' => $data['unread']
            ];
        }
        return $contacts;
    }

    public function message_users($userId, $search = '', $filter = 'all')
    {
        $following = $this->db->table('user_follows')->select('followee_id')
            ->where('follower_id', $userId)->get_all(PDO::FETCH_COLUMN);
        $followingIds = array_fill_keys(array_map('intval', $following), true);
        $unreadMessages = $this->db->table('messages')->select('sender_id')
            ->where('receiver_id', $userId)->where_null('read_at')->get_all(PDO::FETCH_COLUMN);
        $unreadByUser = [];
        foreach ($unreadMessages as $senderId) {
            $senderId = (int) $senderId;
            $unreadByUser[$senderId] = ($unreadByUser[$senderId] ?? 0) + 1;
        }

        $query = $this->db->table('users')
            ->select('id AS user_id, username, first_name, last_name, profile_pic')
            ->where('is_active', 1)->where('id', '<>', $userId);
        $filterIds = $filter === 'following' ? array_keys($followingIds)
            : ($filter === 'unread' ? array_keys($unreadByUser) : []);
        if ($filter !== 'all') {
            if (!$filterIds) {
                return [];
            }
            $query->grouped(function ($db) use ($filterIds) {
                foreach ($filterIds as $index => $filterId) {
                    $index === 0 ? $db->where('id', $filterId) : $db->or_where('id', $filterId);
                }
            });
        }
        if ($search !== '') {
            $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($terms as $word) {
                $term = '%' . $word . '%';
                $query->grouped(function ($db) use ($term) {
                    $db->like('username', $term)
                        ->or_like('first_name', $term)
                        ->or_like('last_name', $term)
                        ->or_like('email', $term);
                });
            }
        }
        $users = $query->order_by('first_name')->limit(100)->get_all(PDO::FETCH_ASSOC);
        if (!$users) {
            return [];
        }

        foreach ($users as &$user) {
            $id = (int) $user['user_id'];
            $user['following'] = isset($followingIds[$id]);
            $user['unread'] = $unreadByUser[$id] ?? 0;
        }
        unset($user);
        return $users;
    }

    public function conversation($userId, $otherId, $limit = 100)
    {
        $this->db->table('messages')->where(['sender_id' => $otherId, 'receiver_id' => $userId])
            ->where_null('read_at')->update(['read_at' => date('Y-m-d H:i:s')]);
        return $this->db->table('messages')
            ->select('messages.message_id, messages.sender_id, messages.receiver_id, messages.body, messages.created_at, sender.profile_pic AS sender_profile_pic, receiver.profile_pic AS receiver_profile_pic')
            ->inner_join('users sender', 'sender.id = messages.sender_id')
            ->inner_join('users receiver', 'receiver.id = messages.receiver_id')
            ->grouped(function ($db) use ($userId, $otherId) {
                $db->where(['sender_id' => $userId, 'receiver_id' => $otherId])
                    ->or_where(['sender_id' => $otherId, 'receiver_id' => $userId]);
            })->order_by('message_id', 'DESC')->limit(max(1, min((int) $limit, 200)))->get_all(PDO::FETCH_ASSOC);
    }

    public function send_message($senderId, $receiverId, $body)
    {
        return (int) $this->db->table('messages')->insert(['sender_id' => $senderId, 'receiver_id' => $receiverId, 'body' => $body]);
    }

    public function unread_message_count($userId)
    {
        return (int) $this->db->table('messages')->where(['receiver_id' => $userId])->where_null('read_at')->count();
    }

    public function orders($userId = null)
    {
        $query = $this->db->table('orders')
            ->select('orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.payment_method, orders.paid_at, orders.xendit_invoice_url, orders.delivery_address, orders.delivery_latitude, orders.delivery_longitude, orders.requested_delivery_at, orders.shipping_provider, orders.transaction_reference, orders.tracking_number, orders.created_at, u.username AS buyer, u.first_name AS buyer_first, u.last_name AS buyer_last, u.email AS buyer_email, u.address AS buyer_address, order_shipments.assignment_id, order_shipments.status AS shipping_status, order_shipments.transporter_id, transporter.first_name AS transporter_first, transporter.last_name AS transporter_last, transporter.username AS transporter_username')
            ->inner_join('users u', 'u.id = orders.buyer_id');
        $query->left_join('order_shipments', "order_shipments.order_id = orders.order_id AND order_shipments.status NOT IN ('rejected','cancelled')");
        $query->left_join('users transporter', 'transporter.id = order_shipments.transporter_id');
        if ($userId !== null) {
            $query->where('orders.buyer_id', $userId);
        }
        $orderRows = $query->order_by('orders.order_id', 'DESC')->limit(200)->get_all(PDO::FETCH_ASSOC);
        if (!$orderRows) {
            return $orderRows;
        }

        $orderIds = array_map('intval', array_column($orderRows, 'order_id'));
        $items = $this->db->table('order_items')
            ->select('order_items.order_id, order_items.item_id, order_items.listing_id, order_items.seller_id, order_items.product_name, order_items.catalog_id, order_items.subtotal, order_items.unit_price, order_items.quantity, order_items.unit, order_items.selling_unit, order_items.batch_unit, order_items.batch_id, order_items.batch_code, order_items.farm_id, order_items.farm_name, order_items.harvest_date, order_items.expiration_date, order_items.cart_added_at, marketplace_listings.image_url, trace_codes.trace_code, trace_codes.qr_url')
            ->left_join('trace_codes', 'trace_codes.order_item_id = order_items.item_id')
            ->left_join('marketplace_listings', 'marketplace_listings.listing_id = order_items.listing_id')
            ->grouped(function ($db) use ($orderIds) {
                foreach ($orderIds as $index => $orderId) {
                    $index === 0 ? $db->where('order_items.order_id', $orderId) : $db->or_where('order_items.order_id', $orderId);
                }
            })->order_by('order_items.item_id')
            ->get_all(PDO::FETCH_ASSOC);
        $itemsByOrder = [];
        foreach ($items as $item) {
            $orderId = (int) $item['order_id'];
            unset($item['order_id']);
            $itemsByOrder[$orderId][] = $item;
        }
        foreach ($orderRows as &$order) {
            $order['items'] = $itemsByOrder[(int) $order['order_id']] ?? [];
        }
        unset($order);
        $this->attach_order_history($orderRows);
        return $orderRows;
    }

    public function orders_for_seller($sellerId)
    {
        $orderIds = $this->db->table('order_items')->select('order_id')
            ->where('seller_id', $sellerId)->get_all(PDO::FETCH_COLUMN);
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) {
            return [];
        }
        $assignmentQuery = $this->db->table('order_shipments')
            ->select('order_shipments.order_id, order_shipments.assignment_id, order_shipments.status AS shipping_status, order_shipments.transporter_id, transporter.first_name AS transporter_first, transporter.last_name AS transporter_last, transporter.username AS transporter_username')
            ->left_join('users transporter', 'transporter.id = order_shipments.transporter_id')
            ->where('order_shipments.status', '<>', 'rejected')
            ->where('order_shipments.status', '<>', 'cancelled');
        $this->where_ids($assignmentQuery, 'order_shipments.order_id', $orderIds);
        $assignments = $assignmentQuery->order_by('order_shipments.assignment_id', 'DESC')->get_all(PDO::FETCH_ASSOC);
        $assignmentByOrder = [];
        foreach ($assignments as $assignment) {
            $assignmentByOrder[(int) $assignment['order_id']] ??= $assignment;
        }
        $rows = $this->db->table('orders')
            ->select('orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.payment_method, orders.delivery_address, orders.requested_delivery_at, orders.shipping_provider, orders.transaction_reference, orders.tracking_number, orders.paid_at, orders.created_at, buyer.username AS buyer, buyer.first_name AS buyer_first, buyer.last_name AS buyer_last, buyer.email AS buyer_email, buyer.address AS buyer_address')
            ->inner_join('users buyer', 'buyer.id = orders.buyer_id');
        $this->where_ids($rows, 'orders.order_id', $orderIds);
        $rows = $rows->order_by('orders.order_id', 'DESC')->limit(200)->get_all(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        foreach ($rows as &$order) {
            $order += [
                'assignment_id' => null,
                'shipping_status' => null,
                'transporter_id' => null,
                'transporter_first' => null,
                'transporter_last' => null,
                'transporter_username' => null,
            ];
            $assignment = $assignmentByOrder[(int) $order['order_id']] ?? [];
            $order = array_merge($order, array_diff_key($assignment, ['order_id' => true]));
        }
        unset($order);
        $itemRows = $this->db->table('order_items')
            ->select('order_items.order_id, order_items.item_id, order_items.listing_id, order_items.seller_id, order_items.product_name, order_items.catalog_id, order_items.unit_price, order_items.subtotal, order_items.quantity, order_items.unit, order_items.selling_unit, order_items.batch_unit, order_items.batch_id, order_items.batch_code, order_items.farm_id, order_items.farm_name, order_items.harvest_date, order_items.expiration_date, order_items.cart_added_at, listings.image_url, products.product_name AS catalog_name, trace_codes.trace_code, trace_codes.qr_url')
            ->left_join('marketplace_listings listings', 'listings.listing_id = order_items.listing_id')
            ->left_join('products', 'products.product_id = order_items.catalog_id')
            ->left_join('trace_codes', 'trace_codes.order_item_id = order_items.item_id')
            ->where('order_items.seller_id', $sellerId);
        $this->where_ids($itemRows, 'order_items.order_id', array_column($rows, 'order_id'));
        $items = $itemRows->order_by('order_items.item_id')->get_all(PDO::FETCH_ASSOC);
        $itemIds = array_map('intval', array_column($items, 'item_id'));
        $allocationQuery = $this->db->table('batch_allocations')->select('order_item_id')
            ->select_sum('quantity', 'allocated_quantity')->group_by('order_item_id');
        $allocations = $this->where_ids($allocationQuery, 'order_item_id', $itemIds)
            ->get_all(PDO::FETCH_ASSOC);
        $allocatedByItem = [];
        foreach ($allocations as $allocation) {
            $allocatedByItem[(int) $allocation['order_item_id']] = (float) $allocation['allocated_quantity'];
        }
        $itemsByOrder = [];
        foreach ($items as $item) {
            $item['allocated_quantity'] = $allocatedByItem[(int) $item['item_id']] ?? 0;
            $itemsByOrder[(int) $item['order_id']][] = $item;
        }
        foreach ($rows as &$order) {
            $order['items'] = $itemsByOrder[(int) $order['order_id']] ?? [];
        }
        unset($order);
        $this->attach_order_history($rows);
        return $rows;
    }

    public function orders_for_transporter($transporterId)
    {
        $rows = $this->db->table('order_shipments')
            ->select('order_shipments.assignment_id, order_shipments.status AS shipping_status, order_shipments.origin, order_shipments.destination, order_shipments.vehicle_number AS shipment_vehicle_number, order_shipments.remarks AS shipping_remarks, order_shipments.created_at AS shipment_created_at, orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.payment_method, orders.paid_at, orders.delivery_address, orders.delivery_latitude, orders.delivery_longitude, orders.shipping_provider, orders.tracking_number, orders.created_at, buyer.username AS buyer, buyer.first_name AS buyer_first, buyer.last_name AS buyer_last, farmer.first_name AS farmer_first, farmer.last_name AS farmer_last, farmer.username AS farmer_username')
            ->inner_join('orders', 'orders.order_id = order_shipments.order_id')
            ->inner_join('users buyer', 'buyer.id = orders.buyer_id')
            ->inner_join('users farmer', 'farmer.id = order_shipments.farmer_id')
            ->where('order_shipments.transporter_id', $transporterId)
            ->where('order_shipments.status', '<>', 'rejected')
            ->where('order_shipments.status', '<>', 'cancelled')
            ->order_by('order_shipments.assignment_id', 'DESC')->limit(200)->get_all(PDO::FETCH_ASSOC);
        if (!$rows) {
            return [];
        }
        $orderIds = array_map('intval', array_column($rows, 'order_id'));
        $itemQuery = $this->db->table('order_items')
            ->select('order_items.order_id, order_items.item_id, order_items.listing_id, order_items.seller_id, order_items.product_name, order_items.subtotal, order_items.unit_price, order_items.quantity, order_items.unit, order_items.batch_id, order_items.batch_code, order_items.farm_id, order_items.farm_name, order_items.harvest_date, order_items.expiration_date, listings.image_url, trace_codes.trace_code, trace_codes.qr_url')
            ->left_join('marketplace_listings listings', 'listings.listing_id = order_items.listing_id')
            ->left_join('trace_codes', 'trace_codes.order_item_id = order_items.item_id');
        $items = $this->where_ids($itemQuery, 'order_items.order_id', $orderIds)
            ->order_by('order_items.item_id')->get_all(PDO::FETCH_ASSOC);
        $itemsByOrder = [];
        foreach ($items as $item) {
            $itemsByOrder[(int) $item['order_id']][] = $item;
        }
        foreach ($rows as &$order) {
            $order['items'] = $itemsByOrder[(int) $order['order_id']] ?? [];
        }
        unset($order);
        $this->attach_order_history($rows);
        return $rows;
    }

    private function where_ids($query, $column, array $ids)
    {
        if (!$ids) {
            return $query->where($column, -1);
        }
        return $query->grouped(function ($db) use ($column, $ids) {
            foreach (array_values($ids) as $index => $id) {
                $index === 0 ? $db->where($column, $id) : $db->or_where($column, $id);
            }
        });
    }

    private function attach_order_history(array &$orders)
    {
        if (!$orders) {
            return;
        }
        $ids = array_map('intval', array_column($orders, 'order_id'));
        $historyQuery = $this->db->table('order_status_history')
            ->select('order_id, status, payment_status, note, created_at, history_id');
        $historyRows = $this->where_ids($historyQuery, 'order_id', $ids)
            ->order_by('history_id')->get_all(PDO::FETCH_ASSOC);
        $byOrder = [];
        foreach ($historyRows as $history) {
            $byOrder[(int) $history['order_id']][] = $history;
        }
        foreach ($orders as &$order) {
            $order['history'] = $byOrder[(int) $order['order_id']] ?? [];
        }
        unset($order);
    }

    public function analytics($userId, $role = '')
    {
        if ($role === 'transporter') {
            $shipmentStatuses = $this->db->table('order_shipments')
                ->select('status')->select_count('assignment_id', 'count')
                ->where('transporter_id', $userId)
                ->where('status', '<>', 'rejected')->where('status', '<>', 'cancelled')
                ->group_by('status')->get_all(PDO::FETCH_ASSOC);
            $totalShipments = 0;
            $activeShipments = 0;
            $completedShipments = 0;
            foreach ($shipmentStatuses as &$shipmentStatus) {
                $shipmentStatus['count'] = (int) $shipmentStatus['count'];
                $totalShipments += $shipmentStatus['count'];
                if (in_array($shipmentStatus['status'], ['offered', 'accepted', 'in_transit', 'out_for_delivery'], true)) {
                    $activeShipments += $shipmentStatus['count'];
                }
                if ($shipmentStatus['status'] === 'delivered') {
                    $completedShipments += $shipmentStatus['count'];
                }
            }
            unset($shipmentStatus);
            $recentShipments = $this->db->table('order_shipments')
                ->select('order_shipments.assignment_id, order_shipments.order_id, order_shipments.status, order_shipments.origin, order_shipments.destination, order_shipments.created_at, orders.shipping_provider, buyer.first_name AS buyer_first, buyer.last_name AS buyer_last')
                ->inner_join('orders', 'orders.order_id = order_shipments.order_id')
                ->inner_join('users buyer', 'buyer.id = orders.buyer_id')
                ->where('order_shipments.transporter_id', $userId)
                ->where('order_shipments.status', '<>', 'rejected')->where('order_shipments.status', '<>', 'cancelled')
                ->order_by('order_shipments.assignment_id', 'DESC')->limit(50)->get_all(PDO::FETCH_ASSOC);
            return [
                'my_shipments' => $totalShipments,
                'my_active_shipments' => $activeShipments,
                'my_delivered_shipments' => $completedShipments,
                'my_shipment_statuses' => $shipmentStatuses,
                'my_recent_shipments' => $recentShipments
            ];
        }

        $categories = $this->db->table('marketplace_listings')->select('category')
            ->select_count('category', 'count')->where('is_available', 1)->where('stock', '>', 0)
            ->group_by('category')->order_by('category')->get_all(PDO::FETCH_ASSOC);
        foreach ($categories as &$category) {
            $category['count'] = (int) $category['count'];
        }
        unset($category);
        usort($categories, function ($a, $b) {
            return $b['count'] <=> $a['count'] ?: strcmp($a['category'], $b['category']);
        });
        $orderCount = $this->db->table('orders')->where('buyer_id', $userId)->count();
        $orderSpend = $this->db->table('orders')->select_sum('total_amount', 'total_spend')
            ->where('buyer_id', $userId)->get(PDO::FETCH_ASSOC);
        $ownProducts = $this->db->table('marketplace_listings')->select('listing_id, product_name, category, stock')
            ->where('seller_id', $userId)->get_all(PDO::FETCH_ASSOC);
        $ownListingIds = array_map('intval', array_column($ownProducts, 'listing_id'));
        $ownReviews = ['count' => 0, 'average_rating' => 0.0];
        $topProducts = [];
        if ($ownListingIds) {
            $ownReviews = $this->db->table('product_reviews')
                ->select_count('product_reviews.review_id', 'count')
                ->select_avg('product_reviews.rating', 'average_rating')
                ->inner_join('marketplace_listings', 'marketplace_listings.listing_id = product_reviews.listing_id')
                ->where('marketplace_listings.seller_id', $userId)
                ->get(PDO::FETCH_ASSOC);
            $topProducts = $this->db->table('marketplace_listings')
                ->select('marketplace_listings.listing_id, marketplace_listings.product_name, marketplace_listings.category')
                ->select_avg('product_reviews.rating', 'average_rating')
                ->select_count('product_reviews.review_id', 'review_count')
                ->left_join('product_reviews', 'product_reviews.listing_id = marketplace_listings.listing_id')
                ->where('marketplace_listings.seller_id', $userId)
                ->group_by(['marketplace_listings.listing_id', 'marketplace_listings.product_name', 'marketplace_listings.category'])
                ->get_all(PDO::FETCH_ASSOC);
            foreach ($topProducts as &$product) {
                $product['average_rating'] = (float) ($product['average_rating'] ?? 0);
                $product['review_count'] = (int) $product['review_count'];
            }
            unset($product);
            usort($topProducts, function ($a, $b) {
                return $b['average_rating'] <=> $a['average_rating']
                    ?: $b['review_count'] <=> $a['review_count']
                    ?: $b['listing_id'] <=> $a['listing_id'];
            });
            $topProducts = array_slice($topProducts, 0, 5);
        }
        $paidSales = $this->db->table('order_items')
            ->select('order_items.listing_id, order_items.product_name, order_items.quantity, order_items.unit_price, orders.order_id, orders.buyer_id, orders.paid_at, buyers.first_name AS buyer_first, buyers.last_name AS buyer_last, buyers.username AS buyer_username')
            ->inner_join('orders', 'orders.order_id = order_items.order_id')
            ->inner_join('users buyers', 'buyers.id = orders.buyer_id')
            ->where('order_items.seller_id', $userId)
            ->where('orders.payment_status', 'paid')
            ->get_all(PDO::FETCH_ASSOC);
        $salesSummary = ['buyer_count' => 0, 'units_sold' => 0, 'revenue' => 0, 'paid_orders' => 0];
        $buyersSeen = [];
        $ordersSeen = [];
        $productBuyerTotals = [];
        $monthlyTotals = [];
        $monthlyCutoff = date('Y-m-d', strtotime('-11 months'));
        foreach ($paidSales as $sale) {
            $buyerId = (int) $sale['buyer_id'];
            $orderId = (int) $sale['order_id'];
            $quantity = (float) $sale['quantity'];
            $revenue = $quantity * (float) $sale['unit_price'];
            $buyersSeen[$buyerId] = true;
            $ordersSeen[$orderId] = true;
            $salesSummary['units_sold'] += $quantity;
            $salesSummary['revenue'] += $revenue;

            $key = json_encode([(int) ($sale['listing_id'] ?? 0), $sale['product_name'], $buyerId]);
            if (!isset($productBuyerTotals[$key])) {
                $productBuyerTotals[$key] = [
                    'listing_id' => $sale['listing_id'],
                    'product_name' => $sale['product_name'],
                    'buyer_id' => $buyerId,
                    'buyer_name' => trim(($sale['buyer_first'] ?? '') . ' ' . ($sale['buyer_last'] ?? '')),
                    'buyer_username' => $sale['buyer_username'],
                    'quantity' => 0,
                    'order_ids' => [],
                    'revenue' => 0,
                    'last_purchase' => null,
                ];
            }
            $productBuyerTotals[$key]['quantity'] += $quantity;
            $productBuyerTotals[$key]['order_ids'][$orderId] = true;
            $productBuyerTotals[$key]['revenue'] += $revenue;
            if ($productBuyerTotals[$key]['last_purchase'] === null || $sale['paid_at'] > $productBuyerTotals[$key]['last_purchase']) {
                $productBuyerTotals[$key]['last_purchase'] = $sale['paid_at'];
            }

            if ($sale['paid_at'] >= $monthlyCutoff) {
                $monthKey = date('Y-m', strtotime($sale['paid_at']));
                if (!isset($monthlyTotals[$monthKey])) {
                    $monthlyTotals[$monthKey] = ['month' => $monthKey, 'revenue' => 0, 'units_sold' => 0, 'orders' => []];
                }
                $monthlyTotals[$monthKey]['revenue'] += $revenue;
                $monthlyTotals[$monthKey]['units_sold'] += $quantity;
                $monthlyTotals[$monthKey]['orders'][$orderId] = true;
            }
        }
        $salesSummary['buyer_count'] = count($buyersSeen);
        $salesSummary['paid_orders'] = count($ordersSeen);
        $productBuyers = array_values($productBuyerTotals);
        foreach ($productBuyers as &$buyer) {
            $buyer['order_count'] = count($buyer['order_ids']);
            unset($buyer['order_ids']);
        }
        unset($buyer);
        usort($productBuyers, function ($a, $b) {
            return strcmp((string) $b['last_purchase'], (string) $a['last_purchase'])
                ?: strcmp((string) $a['product_name'], (string) $b['product_name'])
                ?: strcmp((string) $a['buyer_name'], (string) $b['buyer_name']);
        });
        $productBuyers = array_slice($productBuyers, 0, 200);
        $monthlySales = array_values($monthlyTotals);
        foreach ($monthlySales as &$month) {
            $month['paid_orders'] = count($month['orders']);
            unset($month['orders']);
        }
        unset($month);
        usort($monthlySales, function ($a, $b) {
            return strcmp($a['month'], $b['month']);
        });
        foreach ($monthlySales as &$month) {
            $month['revenue'] = (float) $month['revenue'];
            $month['units_sold'] = (float) $month['units_sold'];
            $month['paid_orders'] = (int) $month['paid_orders'];
        }
        unset($month);
        return [
            'active_listings' => (int) $this->db->table('marketplace_listings')->where(['is_available' => 1])->where('stock', '>', 0)->count(),
            'categories' => $categories,
            'farmers' => (int) $this->db->table('users')->where(['role' => 'farmer', 'is_active' => 1])->count(),
            'groups_joined' => (int) $this->db->table('group_members')->where('user_id', $userId)->count(),
            'my_orders' => (int) $orderCount,
            'my_spend' => (float) ($orderSpend['total_spend'] ?? 0),
            'my_posts' => (int) $this->db->table('community_posts')->where(['author_id' => $userId, 'status' => 'approved'])->count(),
            'my_products' => count($ownProducts),
            'my_product_reviews' => (int) ($ownReviews['count'] ?? 0),
            'my_product_average_rating' => (float) ($ownReviews['average_rating'] ?? 0),
            'my_top_products' => $topProducts,
            'my_buyer_count' => (int) ($salesSummary['buyer_count'] ?? 0),
            'my_units_sold' => (float) ($salesSummary['units_sold'] ?? 0),
            'my_sales_revenue' => (float) ($salesSummary['revenue'] ?? 0),
            'my_paid_orders' => (int) ($salesSummary['paid_orders'] ?? 0),
            'my_product_buyers' => $productBuyers,
            'my_monthly_sales' => $monthlySales
        ];
    }

    public function reports($status = null)
    {
        $query = $this->db->table('reports')
            ->select('reports.report_id, reports.reason, reports.status, reports.created_at, reports.resolved_at, reports.reported_user_id, reports.reporter_id, reports.post_id, reports.post_content_snapshot, reports.post_media_url_snapshot, reports.post_media_type_snapshot, reports.post_product_snapshot, reports.review_action, reporter.username AS reported_by, target.username AS reported_user')
            ->left_join('users reporter', 'reporter.id = reports.reporter_id')
            ->left_join('users target', 'target.id = reports.reported_user_id');
        if ($status !== null) {
            $query->where('reports.status', $status);
        }
        return $query->order_by('reports.report_id', 'DESC')->limit(100)->get_all(PDO::FETCH_ASSOC);
    }

    public function report_details($reportId)
    {
        return $this->db->table('reports')
            ->select('report_id, reporter_id, reported_user_id, post_id, reason, status')
            ->where('report_id', $reportId)->limit(1)->get(PDO::FETCH_ASSOC);
    }

    public function create_post_report($reporterId, $reportedUserId, $postId, $reason, array $post)
    {
        $product = null;
        if (!empty($post['listing_id'])) {
            $product = [
                'name' => $post['product_name'] ?? null,
                'category' => $post['category'] ?? null,
                'description' => $post['product_description'] ?? null,
                'price' => $post['price'] ?? null,
                'unit' => $post['unit'] ?? null,
                'image_url' => $post['product_image'] ?? null,
            ];
        }
        return (int) $this->db->table('reports')->insert([
            'reporter_id' => $reporterId,
            'reported_user_id' => $reportedUserId,
            'post_id' => $postId,
            'reason' => $reason,
            'post_content_snapshot' => $post['content'],
            'post_media_url_snapshot' => $post['media_url'],
            'post_media_type_snapshot' => $post['media_type'],
            'post_product_snapshot' => $product === null ? null : json_encode($product, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function resolve_report($reportId, $reviewAction = null)
    {
        $updates = ['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')];
        if ($reviewAction !== null) {
            $updates['review_action'] = $reviewAction;
        }
        return $this->db->table('reports')->where('report_id', $reportId)->update($updates);
    }

    public function report_exists($reportId)
    {
        return (bool) $this->db->table('reports')->select('report_id')->where('report_id', $reportId)->limit(1)->get();
    }
}
