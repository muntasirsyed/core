<?php namespace Flarum\Core\Posts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Flarum\Core\Users\User;
use Flarum\Core\Discussions\Discussion;
use Flarum\Core\Discussions\Search\Fulltext\DriverInterface;

// TODO: In some cases, the use of a post repository incurs extra query expense,
// because for every post retrieved we need to check if the discussion it's in
// is visible. Especially when retrieving a discussion's posts, we can end up
// with an inefficient chain of queries like this:
// 1. Api\Discussions\ShowAction: get discussion (will exit if not visible)
// 2. Discussion@postsVisibleTo: get discussion tags (for post visibility purposes)
// 3. Discussion@postsVisibleTo: get post IDs
// 4. EloquentPostRepository@getIndexForNumber: get discussion
// 5. EloquentPostRepository@getIndexForNumber: get discussion tags (for post visibility purposes)
// 6. EloquentPostRepository@getIndexForNumber: get post index for number
// 7. EloquentPostRepository@findWhere: get post IDs for discussion to check for discussion visibility
// 8. EloquentPostRepository@findWhere: get post IDs in visible discussions
// 9. EloquentPostRepository@findWhere: get posts
// 10. EloquentPostRepository@findWhere: eager load discussion onto posts
// 11. EloquentPostRepository@findWhere: get discussion tags to filter visible posts
// 12. Api\Discussions\ShowAction: eager load users
// 13. Api\Discussions\ShowAction: eager load groups
// 14. Api\Discussions\ShowAction: eager load mentions
// 14. Serializers\DiscussionSerializer: load discussion-user state

class EloquentPostRepository implements PostRepositoryInterface
{
    protected $fulltext;

    public function __construct(DriverInterface $fulltext)
    {
        $this->fulltext = $fulltext;
    }

    /**
     * {@inheritdoc}
     */
    public function findOrFail($id, User $actor = null)
    {
        $posts = $this->findByIds([$id], $actor);

        if (! count($posts)) {
            throw new ModelNotFoundException;
        }

        return $posts->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findWhere($where = [], User $actor = null, $sort = [], $count = null, $start = 0)
    {
        $query = Post::where($where)
            ->skip($start)
            ->take($count);

        foreach ((array) $sort as $field => $order) {
            $query->orderBy($field, $order);
        }

        $ids = $query->lists('id');

        return $this->findByIds($ids, $actor);
    }

    /**
     * {@inheritdoc}
     */
    public function findByIds(array $ids, User $actor = null)
    {
        $ids = $this->filterDiscussionVisibleTo($ids, $actor);

        $posts = Post::with('discussion')->whereIn('id', (array) $ids)->get();

        return $this->filterVisibleTo($posts, $actor);
    }

    /**
     * {@inheritdoc}
     */
    public function findByContent($string, User $actor = null)
    {
        $ids = $this->fulltext->match($string);

        $ids = $this->filterDiscussionVisibleTo($ids, $actor);

        $query = Post::select('id', 'discussion_id')->whereIn('id', $ids);

        foreach ($ids as $id) {
            $query->orderByRaw('id != ?', [$id]);
        }

        $posts = $query->get();

        return $this->filterVisibleTo($posts, $actor);
    }

    /**
     * {@inheritdoc}
     */
    public function getIndexForNumber($discussionId, $number, User $actor = null)
    {
        $query = Discussion::find($discussionId)
            ->postsVisibleTo($actor)
            ->where('time', '<', function ($query) use ($discussionId, $number) {
                $query->select('time')
                      ->from('posts')
                      ->where('discussion_id', $discussionId)
                      ->whereNotNull('number')
                      ->take(1)

                      // We don't add $number as a binding because for some
                      // reason doing so makes the bindings go out of order.
                      ->orderByRaw('ABS(CAST(number AS SIGNED) - '.(int) $number.')');
            });

        return $query->count();
    }

    protected function filterDiscussionVisibleTo($ids, User $actor)
    {
        // For each post ID, we need to make sure that the discussion it's in
        // is visible to the user.
        if ($actor) {
            $ids = Discussion::join('posts', 'discussions.id', '=', 'posts.discussion_id')
                ->whereIn('posts.id', $ids)
                ->whereVisibleTo($actor)
                ->get(['posts.id'])
                ->lists('id');
        }

        return $ids;
    }

    protected function filterVisibleTo($posts, User $actor)
    {
        if ($actor) {
            $posts = $posts->filter(function ($post) use ($actor) {
                return $post->can($actor, 'view');
            });
        }

        return $posts;
    }
}