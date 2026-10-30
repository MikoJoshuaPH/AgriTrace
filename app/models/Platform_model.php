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
        $farmers = $this->db->table('users')->select('id, username, first_name, last_name, address, profile_pic, is_verified')
            ->where(['role' => 'farmer', 'is_active' => 1])->where('id', '<>', $viewerId)
            ->get_all(PDO::FETCH_ASSOC);
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
            ->select('orders.order_id, orders.buyer_id, orders.total_amount, orders.status, orders.payment_status, orders.paid_at, orders.xendit_invoice_url, orders.created_at, u.username AS buyer')
            ->inner_join('users u', 'u.id = orders.buyer_id');
        if ($userId !== null) {
            $query->where('orders.buyer_id', $userId);
        }
        $orderRows = $query->order_by('orders.order_id', 'DESC')->limit(200)->get_all(PDO::FETCH_ASSOC);
        if (!$orderRows) {
            return $orderRows;
        }
        $orderIds = array_map('intval', array_column($orderRows, 'order_id'));
        $items = $this->db->table('order_items')
            ->select('order_items.order_id, order_items.item_id, order_items.listing_id, order_items.seller_id, order_items.product_name, order_items.unit_price, order_items.quantity, order_items.unit, trace_codes.trace_code, trace_codes.qr_url')
            ->left_join('trace_codes', 'trace_codes.order_item_id = order_items.item_id')
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
        return $orderRows;
    }

    public function analytics($userId)
    {
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
            $placeholders = implode(',', array_fill(0, count($ownListingIds), '?'));
            $ownReviews = $this->db->raw(
                'SELECT COUNT(*) AS count, COALESCE(AVG(r.rating), 0) AS average_rating
                 FROM product_reviews r
                 INNER JOIN marketplace_listings l ON l.listing_id = r.listing_id
                 WHERE l.seller_id = ?',
                [(int) $userId]
            )->fetch(PDO::FETCH_ASSOC);
            $topProducts = $this->db->raw(
                'SELECT l.listing_id, l.product_name, l.category,
                        COALESCE(AVG(r.rating), 0) AS average_rating, COUNT(r.review_id) AS review_count
                 FROM marketplace_listings l
                 LEFT JOIN product_reviews r ON r.listing_id = l.listing_id
                 WHERE l.seller_id = ?
                 GROUP BY l.listing_id, l.product_name, l.category
                 ORDER BY average_rating DESC, review_count DESC, l.listing_id DESC
                 LIMIT 5',
                [(int) $userId]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($topProducts as &$product) {
                $product['average_rating'] = (float) $product['average_rating'];
                $product['review_count'] = (int) $product['review_count'];
            }
            unset($product);
        }
        return [
            'active_listings' => (int) $this->db->table('marketplace_listings')->where(['is_available' => 1])->where('stock', '>', 0)->count(),
            'categories' => $categories,
            'farmers' => (int) $this->db->table('users')->where(['role' => 'farmer', 'is_active' => 1])->count(),
            'groups_joined' => (int) $this->db->table('group_members')->where('user_id', $userId)->count(),
            'my_orders' => (int) $orderCount,
            'my_spend' => (float) ($orderSpend['total_spend'] ?? 0),
            'my_pending_posts' => (int) $this->db->table('community_posts')->where(['author_id' => $userId, 'status' => 'pending'])->count(),
            'my_products' => count($ownProducts),
            'my_product_reviews' => (int) ($ownReviews['count'] ?? 0),
            'my_product_average_rating' => (float) ($ownReviews['average_rating'] ?? 0),
            'my_top_products' => $topProducts
        ];
    }

    public function reports($status = 'open')
    {
        return $this->db->table('reports')
            ->select('reports.report_id, reports.reason, reports.status, reports.created_at, reports.reported_user_id, reporter.username AS reported_by, target.username AS reported_user')
            ->left_join('users reporter', 'reporter.id = reports.reporter_id')
            ->left_join('users target', 'target.id = reports.reported_user_id')
            ->where('reports.status', $status)->order_by('reports.report_id', 'DESC')->limit(100)->get_all(PDO::FETCH_ASSOC);
    }

    public function resolve_report($reportId)
    {
        return $this->db->table('reports')->where('report_id', $reportId)->update([
            'status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function report_exists($reportId)
    {
        return (bool) $this->db->table('reports')->select('report_id')->where('report_id', $reportId)->limit(1)->get();
    }
}
