<?php

defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Community features: post deletion, likes, comments, groups, follows,
 * saved items, notifications, direct messages and analytics.
 */
class SocialController extends Controller
{
    private $community;
    private $platform;

    public function __construct()
    {
        parent::__construct();
        $this->call->database();
        $this->call->library('api');
        $this->community = $this->call->model('Community_model');
        $this->platform = $this->call->model('Platform_model');
    }

    /** DELETE /api/posts/{id} — authors can delete their posts; admins can delete any post (rejecting pending ones). */
    public function delete_post($id)
    {
        $this->api->require_method('DELETE');
        $auth = $this->api->require_jwt();
        $post = $this->visible_post($id, $auth);
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $userId = (int) $auth['sub'];

        if (!$isAdmin && (int) $post['author_id'] !== $userId) {
            $this->api->respond_error('You can only delete your own posts.', 403);
        }
        $this->community->delete_post((int) $id);
        if ((int) $post['author_id'] !== $userId && $post['status'] === 'pending') {
            $this->platform->notify((int) $post['author_id'], 'Post not approved', 'An administrator removed your post.');
        }
        $this->platform->audit($userId, 'DELETE_POST', 'Post', (int) $id, null);
        $this->api->respond(['message' => 'Post deleted.']);
    }

    /** PUT /api/posts/{id} — only the post author may update its content and media. */
    public function update_post($id)
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $post = $this->visible_post($id, $auth);
        $userId = (int) $auth['sub'];

        if ((int) $post['author_id'] !== $userId) {
            $this->api->respond_error('You can only edit your own posts.', 403);
        }

        $input = $this->api->body();
        if (!isset($input['content']) || !is_string($input['content'])) {
            $this->api->respond_error('Post content is required.', 422);
        }
        $content = trim($input['content']);
        if ($content === '' || mb_strlen($content) > 5000) {
            $this->api->respond_error('Post content must be between 1 and 5000 characters.', 422);
        }

        $updates = ['content' => $content];
        if (array_key_exists('media_url', $input)) {
            if ($input['media_url'] !== null && !is_string($input['media_url'])) {
                $this->api->respond_error('Post media URL must be a string.', 422);
            }
            $mediaUrl = trim((string) ($input['media_url'] ?? ''));
            if (strlen($mediaUrl) > 60000) {
                $this->api->respond_error('Post media is too large.', 422);
            }
            $updates['media_url'] = $mediaUrl === '' ? null : $mediaUrl;
        }
        if (array_key_exists('media_type', $input)) {
            if ($input['media_type'] !== null && !is_string($input['media_type'])) {
                $this->api->respond_error('Post media type must be a string.', 422);
            }
            $mediaType = trim((string) ($input['media_type'] ?? ''));
            if (strlen($mediaType) > 30) {
                $this->api->respond_error('Post media type is invalid.', 422);
            }
            $updates['media_type'] = $mediaType === '' ? null : $mediaType;
        }

        $this->community->update_post((int) $id, $updates);
        if (array_key_exists('media_url', $updates) && !empty($post['listing_id'])) {
            $this->community->update_listing_image((int) $post['listing_id'], $updates['media_url']);
        }
        $this->platform->audit($userId, 'UPDATE_POST', 'Post', (int) $id, $content);
        $this->api->respond(['message' => 'Post updated successfully.']);
    }

    /** POST /api/posts/{id}/like */
    public function like($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $this->visible_post($id, $auth);
        [$liked, $likes] = $this->community->toggle_like((int) $id, (int) $auth['sub']);
        $this->api->respond(['liked' => $liked, 'likes' => $likes]);
    }

    /** POST /api/posts/{id}/comments */
    public function comment($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $post = $this->visible_post($id, $auth);
        $input = $this->api->body();
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 1000) {
            $this->api->respond_error('Comments must be between 1 and 1000 characters.', 422);
        }
        $userId = (int) $auth['sub'];
        $comment = $this->community->add_comment((int) $id, $userId, $content);
        if ((int) $post['author_id'] !== $userId) {
            $this->platform->notify((int) $post['author_id'], 'New comment', 'Someone commented on your post.');
        }
        $this->api->respond(['message' => 'Comment posted.', 'comment' => $comment], 201);
    }

    /** GET /api/groups */
    public function groups()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $this->api->respond(['data' => $this->platform->groups((int) $auth['sub'])]);
    }

    /** POST /api/groups/{id}/toggle — join or leave a group. */
    public function toggle_group($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (!$this->platform->group_exists((int) $id)) {
            $this->api->respond_error('Group not found.', 404);
        }
        $joined = $this->platform->toggle_group((int) $auth['sub'], (int) $id);
        $this->api->respond(['joined' => $joined]);
    }

    /** GET /api/farmers */
    public function farmers()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $rows = $this->platform->farmers((int) $auth['sub']);
        foreach ($rows as &$row) {
            $row['following'] = (bool) $row['following'];
            $row['followers'] = (int) $row['followers'];
        }
        $this->api->respond(['data' => $rows]);
    }

    /** GET /api/members/{id} — public profile fields for a signed-in viewer. */
    public function member_profile($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->api->respond_error('Member not found.', 404);
        }

        $member = $this->db->table('users')
            ->select('id, username, first_name, last_name, role, profile_pic, is_verified, is_active, created_at')
            ->where(['id' => $id, 'is_active' => 1])
            ->limit(1)
            ->get(PDO::FETCH_ASSOC);
        if (!$member) {
            $this->api->respond_error('Member not found.', 404);
        }

        $member['following'] = (int) $auth['sub'] !== (int) $id
            && $this->platform->is_following((int) $auth['sub'], (int) $id);
        $this->api->respond($member);
    }

    /** POST /api/follows/{id}/toggle */
    public function toggle_follow($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if ((int) $id === (int) $auth['sub'] || !$this->platform->user_exists((int) $id)) {
            $this->api->respond_error('Farmer not found.', 404);
        }
        $following = $this->platform->toggle_follow((int) $auth['sub'], (int) $id);
        $this->api->respond(['following' => $following]);
    }

    /** GET /api/saved */
    public function saved()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $this->api->respond(['data' => $this->platform->saved_listings((int) $auth['sub'])]);
    }

    /** POST /api/saved/{id}/toggle */
    public function toggle_saved($id)
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        if (!$this->platform->listing_exists((int) $id)) {
            $this->api->respond_error('Product not found.', 404);
        }
        $saved = $this->platform->toggle_saved((int) $auth['sub'], (int) $id);
        $this->api->respond(['saved' => $saved]);
    }

    /** GET /api/notifications — only the caller's own notifications. */
    public function notifications()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $userId = (int) $auth['sub'];
        $items = $this->platform->notifications($userId);
        foreach ($items as &$item) {
            $item['is_unread'] = (bool) $item['is_unread'];
        }
        $unread = count(array_filter($items, fn ($i) => $i['is_unread']));
        $this->api->respond(['data' => $items, 'unread' => $unread]);
    }

    /** PUT /api/notifications/read */
    public function read_notifications()
    {
        $this->api->require_method('PUT');
        $auth = $this->api->require_jwt();
        $this->platform->mark_notifications_read((int) $auth['sub']);
        $this->api->respond(['message' => 'Notifications marked as read.']);
    }

    /** GET /api/messages/contacts */
    public function contacts()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $rows = $this->platform->contacts((int) $auth['sub']);
        foreach ($rows as &$row) {
            $row['unread'] = (int) $row['unread'];
        }
        $this->api->respond(['data' => $rows]);
    }

    /** GET /api/messages/users — active users with their following and unread-message state. */
    public function message_users()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $query = $this->api->get_query_params();
        $search = $query['q'] ?? '';
        $filter = $query['filter'] ?? 'all';
        if (!is_string($search) || !is_string($filter)
            || !in_array($filter, ['all', 'unread', 'following'], true)) {
            $this->api->respond_error('Invalid user search or filter.', 422);
        }
        $search = trim($search);
        if (mb_strlen($search) > 100) {
            $this->api->respond_error('Search must be 100 characters or fewer.', 422);
        }
        $this->api->respond(['data' => $this->platform->message_users((int) $auth['sub'], $search, $filter)]);
    }

    /** GET /api/messages/{id} — conversation with one user; marks their messages as read. */
    public function conversation($id)
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        if (!$this->platform->user_exists((int) $id)) {
            $this->api->respond_error('User not found.', 404);
        }
        $messages = array_reverse($this->platform->conversation((int) $auth['sub'], (int) $id));
        $this->api->respond(['data' => $messages]);
    }

    /** POST /api/messages {receiver_id, body} */
    public function send_message()
    {
        $this->api->require_method('POST');
        $auth = $this->api->require_jwt();
        $input = $this->api->body();
        $receiver = (int) ($input['receiver_id'] ?? 0);
        $body = trim((string) ($input['body'] ?? ''));
        if ($receiver <= 0 || $receiver === (int) $auth['sub'] || !$this->platform->user_exists($receiver)) {
            $this->api->respond_error('Choose a valid person to message.', 422);
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            $this->api->respond_error('Messages must be between 1 and 2000 characters.', 422);
        }
        $id = $this->platform->send_message((int) $auth['sub'], $receiver, $body);
        $this->api->respond(['message' => 'Message sent.', 'message_id' => $id], 201);
    }

    /** GET /api/analytics */
    public function analytics()
    {
        $this->api->require_method('GET');
        $auth = $this->api->require_jwt();
        $this->api->respond($this->platform->analytics((int) $auth['sub']));
    }

    /**
     * Returns a post the caller may act on: approved posts, or their own pending posts
     * (admins may act on any post). Responds 404 otherwise.
     */
    private function visible_post($id, array $auth)
    {
        $post = $this->community->post_owner((int) $id);
        if (!$post) {
            $this->api->respond_error('Post not found.', 404);
        }
        $isAdmin = ($auth['role'] ?? '') === 'admin';
        $isOwner = (int) $post['author_id'] === (int) $auth['sub'];
        if ($post['status'] !== 'approved' && !$isOwner && !$isAdmin) {
            $this->api->respond_error('Post not found.', 404);
        }
        return $post;
    }
}
