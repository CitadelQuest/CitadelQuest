/**
 * CQ Feed API Service
 * Handles all API calls for feeds and posts.
 * 
 */
export class CQFeedApiService {

    // ========================================
    // My Feeds
    // ========================================

    async listMyFeeds() {
        const resp = await fetch('/api/feed/my-feeds');
        return resp.json();
    }

    async createFeed(data) {
        const resp = await fetch('/api/feed/my-feeds', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        return resp.json();
    }

    async updateFeed(id, data) {
        const resp = await fetch(`/api/feed/my-feeds/${id}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        return resp.json();
    }

    async deleteFeed(id) {
        const resp = await fetch(`/api/feed/my-feeds/${id}`, {
            method: 'DELETE',
        });
        return resp.json();
    }

    // ========================================
    // My Posts
    // ========================================

    async listMyPosts(feedId, page = 1, limit = 20) {
        const resp = await fetch(`/api/feed/my-feeds/${feedId}/posts?page=${page}&limit=${limit}`);
        return resp.json();
    }

    async createPost(feedId, content) {
        const resp = await fetch(`/api/feed/my-feeds/${feedId}/posts`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content }),
        });
        return resp.json();
    }

    async updatePost(postId, data) {
        const resp = await fetch(`/api/feed/posts/${postId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        });
        return resp.json();
    }

    async deletePost(postId) {
        const resp = await fetch(`/api/feed/posts/${postId}`, {
            method: 'DELETE',
        });
        return resp.json();
    }

    // ========================================
    // Subscribed Federation Feeds
    // ========================================

    async syncSubscriptions() {
        const resp = await fetch('/api/feed/sync-subscriptions', { method: 'POST' });
        return resp.json();
    }

    async listSubscribed() {
        const resp = await fetch('/api/feed/subscribed');
        return resp.json();
    }

    async fetchSubscribed(feedId) {
        const resp = await fetch(`/api/feed/subscribed/${feedId}/fetch`, {
            method: 'POST',
        });
        return resp.json();
    }

    async fetchAllSubscribed() {
        const resp = await fetch('/api/feed/subscribed/fetch-all', {
            method: 'POST',
        });
        return resp.json();
    }

    async unsubscribe(feedId) {
        const resp = await fetch(`/api/feed/subscribed/${feedId}`, {
            method: 'DELETE',
        });
        return resp.json();
    }

    async toggleSubscribed(feedId) {
        const resp = await fetch(`/api/feed/subscribed/${feedId}/toggle`, {
            method: 'POST',
        });
        return resp.json();
    }

    // ========================================
    // Timeline
    // ========================================

    async getTimeline(page = 1, limit = 20) {
        const resp = await fetch(`/api/feed/timeline?page=${page}&limit=${limit}`);
        return resp.json();
    }

    // ========================================
    // Reactions
    // ========================================

    async getPostStats(postId) {
        const resp = await fetch(`/api/feed/timeline/${postId}/stats`);
        return resp.json();
    }

    // ========================================
    // Comments
    // ========================================

    async addComment(postId, content, parentId = null) {
        const resp = await fetch('/api/feed/timeline/comment', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId, content, parent_id: parentId }),
        });
        return resp.json();
    }

    async listComments(postId, page = 1, limit = 50) {
        const resp = await fetch(`/api/feed/timeline/${postId}/comments?page=${page}&limit=${limit}`);
        return resp.json();
    }

    async updateComment(commentId, postId, content) {
        const resp = await fetch(`/api/feed/timeline/comment/${commentId}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId, content }),
        });
        return resp.json();
    }

    async deleteComment(commentId, postId) {
        const resp = await fetch(`/api/feed/timeline/comment/${commentId}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId }),
        });
        return resp.json();
    }

    async toggleComment(commentId, postId) {
        const resp = await fetch(`/api/feed/timeline/comment/${commentId}/toggle`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId }),
        });
        return resp.json();
    }

    async reactToPost(postId, reaction) {
        const resp = await fetch('/api/feed/timeline/react', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ post_id: postId, reaction }),
        });
        return resp.json();
    }
}
