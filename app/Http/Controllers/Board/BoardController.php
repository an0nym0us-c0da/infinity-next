<?php

namespace App\Http\Controllers\Board;

use App\Board;
use App\FileStorage;
use App\OptionGroup;
use App\Post;
use App\Http\Controllers\Controller;
use App\Http\Requests\PostRequest;
use App\Support\IP;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Cache;
use File;
use Request;
use Response;
use Validator;

/**
 * Controller for board related views.
 *
 * @category   Controller
 *
 * @author     Joshua Moon <josh@jaw.sh>
 * @copyright  2016 Infinity Next Development Group
 * @license    http://www.gnu.org/licenses/agpl-3.0.en.html AGPL3
 *
 * @since      0.5.1
 */
class BoardController extends Controller
{
    const VIEW_BOARD = 'board';
    const VIEW_CATALOG = 'catalog';
    const VIEW_CONFIG = 'board.config';
    const VIEW_LANDING = 'board.landing';
    const VIEW_THREAD = 'board';
    const VIEW_LOGS = 'board.logs';

    /**
     * Show the board index for the user.
     * This is usually the last few threads, depending on the optional page
     * parameter, which determines the thread offset.
     *
     * @param \App\Board $board
     * @param int        $page
     *
     * @return Response
     */
    public function getIndex(Board $board, $page = 1)
    {
        // Determine what page we are on.
        $pages = $board->getPageCount();

        // Clamp the page to real values.
        if ($page <= 0) {
            $page = 1;
        } elseif ($page > $pages) {
            $page = $pages;
        }

        // Determine if we have a next/prev button.
        $pagePrev = ($page > 1) ? $page - 1 : false;
        $pageNext = ($page < $pages) ? $page + 1 : false;

        // Load our list of threads and their latest replies.
        $posts = $board->getThreadsForIndex($page);

        return $this->view(static::VIEW_BOARD, [
            'board' => &$board,
            'posts' => $posts,
            'reply_to' => false,

            'pages' => $pages,
            'page' => $page,
            'pagePrev' => $pagePrev,
            'pageNext' => $pageNext,
        ]);
    }

    /**
     * Show the catalog (gridded) board view.
     *
     * @param \App\Board $board
     *
     * @return Response
     */
    public function getCatalog(Board $board)
    {
        // Load our list of threads and their latest replies.
        $posts = $board->getThreadsForCatalog();

        return $this->view(static::VIEW_CATALOG, [
            'board' => $board,
            'posts' => $posts,
            'reply_to' => false,
        ]);
    }

    /**
     * Renders public config.
     *
     * @param \App\Board $board
     *
     * @return REsponse
     */
    public function getConfig(Board $board)
    {
        $optionGroups = OptionGroup::getBoardConfig($board);

        return $this->view(static::VIEW_CONFIG, [
            'board' => $board,
            'groups' => $optionGroups,
        ]);
    }

    /**
     * Renders a thread.
     *
     * @param \App\Board $board
     *
     * @return Response
     */
    public function getLogs(Board $board)
    {
        $this->authorize('audit', $board);
        
        return $this->view(static::VIEW_LOGS, [
            'board' => $board,
            'logs' => $board->getLogs(),
        ]);
    }

    /**
     * Renders a thread.
     *
     * @param Board  $board
     * @param Post   $thread
     * @param string $splice  2ch-style URL API
     *
     * @return Response
     */
    public function getThread(Board $board, Post $thread, $splice = null)
    {
        if (!$thread->exists) {
            return abort(404);
        }
        elseif ($thread->reply_to) {
            return redirect("{$board->board_uri}/thread/{$thread->reply_to_board_id}#{$thread->board_id}");
        }

        if (is_string($splice)) {
            $thread = $thread->getReplySplice($splice);
        }

        if ($thread === false) {
            abort(400);
        }

        return $this->view(static::VIEW_THREAD, [
            'board' => &$board,
            'posts' => [$thread],
            'reply_to' => $thread,
            'updater' => true,
        ]);
    }

    /**
     * Handles a redirect landing page.
     *
     * @param Board $board
     * @param Post  $thread
     *
     * @return Response
     */
    public function getThreadRedirect(Board $board, Post $thread)
    {
        return $this->view(static::VIEW_LANDING, [
            'board' => $board,
            'url' => $thread->getURL(),
            'message' => trans($thread->reply_to_board_id ? 'board.landing.reply_submitted' : 'board.landing.thread_submitted'),
        ]);
    }

    /**
     * Handles the creation of a new thread or reply.
     *
     * @param \App\Http\Requests\PostRequest $request
     * @param Board                          $board
     * @param Post                           $thread
     *
     * @return Response (redirects to the thread view)
     */
    public function putThread(PostRequest $request, Board $board, Post $thread = null)
    {
        // Create the post.
        $post = new Post($request->all());
        $post->submitTo($board, $thread);

        // Log staff posts.
        if ($post->capcode_id) {
            $this->log('log.post.capcode', $post, [
                'board_id' => $post->board_id,
                'board_uri' => $post->board_uri,
                'capcode' => $post->capcode->getCapcodeName(),
                'role' => $post->capcode->role,
            ]);
        }

        $input = $request->only('updatesOnly', 'updateHtml', 'updatedSince');

        if ($request->wantsJson()) {
            if (!is_null($thread) && $thread->exists) {
                $updatedSince = Carbon::createFromTimestamp($request->input('updatedSince', Carbon::now()->timestamp));
                $includeHTML = isset($input['updateHtml']);

                $post->setAppendHTML($includeHTML);

                $posts = Post::getUpdates($updatedSince, $board, $thread, $includeHTML);
                $posts->push($post);
                $posts->sortBy('board_id');

                return $posts;
            }
            else {
                return $post;
            }
        }

        // Redirect to splash page.
        return redirect("/{$board->board_uri}/redirect/{$post->board_id}");
    }

    /**
     * Supplies a user generated stylesheet based on board options.
     *
     * @param Board $board
     *
     * @return Response
     */
    public function getStylesheet(Board $board)
    {
        if (!$board->hasStylesheet()) {
            abort(404);
        }

        $stylesheet = $board->getStylesheet();
        $statusCode = 200;
        $contentType = 'text/css';
        $cacheTime = 86400; // 1 day

        if (strlen((string) $stylesheet) == 0) {
            return abort(404);
        }

        return response($stylesheet, $statusCode)
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', "public, max-age={$cacheTime}, pre-check={$cacheTime}");
    }

    /**
     * Supplies a user generated stylesheet based on board options in plaintext as to avoid compression.
     *
     * @param Board $board
     *
     * @return Response
     */
    public function getStylesheetAsText(Board $board)
    {
        return $this->getStylesheet($board)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Checks if a file exists.
     *
     * @param Board   $board
     *
     * @return json
     */
    public function getFile(Board $board)
    {
        $hash = Request::input('md5');
        $storage = FileStorage::getHash($hash);

        if (is_null($storage) || !$storage->hasFile()) {
            return [$hash => null];
        }

        return [$hash => $storage];
    }

    /**
     * Uploads a single file.
     *
     * @param Board   $board
     *
     * @return json
     */
    public function putFile(Board $board)
    {
        $input = Request::all();
        $rules = [];

        PostRequest::rulesForFiles($board, $rules);
        $rules['files'][] = 'required';

        $validator = Validator::make($input, $rules);

        if (!$validator->passes()) {
            return json_encode([
                'errors' => $validator->errors(),
            ]);
        }


        $storage = new Collection();

        foreach ($input['files'] as $file) {
            $ip = new IP;
            $uploadSize = (int) Cache::get('upstream_data_for_'.$ip->toLong(), 0);

            if ($uploadSize <= 52430000) {
                Cache::increment('upstream_data_for_'.$ip->toLong(), $file->getSize(), 2);

                $newStorage = FileStorage::storeUpload($file);
                $storage[$newStorage->hash] = $newStorage;

                Cache::decrement('upstream_data_for_'.$ip->toLong(), $file->getSize());
            } else {
                return abort(429);
            }
        }

        return $storage;
    }
}
