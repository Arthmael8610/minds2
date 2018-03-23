<?php
/**
 * Minds Newsfeed API
 *
 * @version 1
 * @author Mark Harding
 */

namespace Minds\Controllers\api\v1;

use Minds\Api\Factory;
use Minds\Core;
use Minds\Core\Security;
use Minds\Entities;
use Minds\Entities\Activity;
use Minds\Helpers;
use Minds\Helpers\Counters;
use Minds\Interfaces;
use Minds\Interfaces\Flaggable;

class newsfeed implements Interfaces\Api
{
    /**
     * Returns the newsfeed
     * @param array $pages
     *
     * API:: /v1/newsfeed/
     */
    public function get($pages)
    {
        $response = array();

        if (!isset($pages[0])) {
            $pages[0] = 'network';
        }

        $pinned_guids = null;
        switch ($pages[0]) {
            case 'single':
                $activity = new Activity($pages[1]);

                if (!$activity->guid || Helpers\Flags::shouldFail($activity)) {
                    return Factory::response(['status' => 'error']);
                }

                return Factory::response(array('activity' => $activity->export()));
                break;
            default:
            case 'personal':
                $options = array(
                    'owner_guid' => isset($pages[1]) ? $pages[1] : elgg_get_logged_in_user_guid()
                );
                if (isset($_GET['pinned']) && count($_GET['pinned']) > 0) {
                    $pinned_guids = [];
                    $p = explode(',', $_GET['pinned']);
                    foreach($p as $guid) {
                        $pinned_guids[] = (string)$guid;
                    }
                }

                break;
            case 'network':
                $options = array(
                    'network' => isset($pages[1]) ? $pages[1] : core\Session::getLoggedInUserGuid()
                );
                break;
            case 'top':
                $offset = isset($_GET['offset']) ? $_GET['offset'] : "";
                $result = Core\Di\Di::_()->get('Trending\Repository')
                    ->getList([
                        'type' => 'newsfeed',
                        'rating' => isset($_GET['rating']) ? $_GET['rating'] : 1,
                        'limit' => 12,
                        'offset' => $offset
                      ]);
                ksort($result['guids']);
                $options['guids'] = $result['guids'];
                if (!$options['guids']) {
                    return Factory::response([]);
                }
                $loadNext = base64_encode($result['token']);
                break;
            case 'featured':
                $db = Core\Di\Di::_()->get('Database\Cassandra\Indexes');
                $offset = isset($_GET['offset']) ? $_GET['offset'] : "";
                $guids = $db->getRow('activity:featured', ['limit' => 24, 'offset' => $offset]);
                if ($guids) {
                    $options['guids'] = $guids;
                } else {
                    return Factory::response([]);
                }
                break;
            case 'container':
                $options = array(
                    'container_guid' => isset($pages[1]) ? $pages[1] : elgg_get_logged_in_user_guid()
                );
                break;
        }

        if (get_input('count')) {
            $offset = get_input('offset', '');

            if (!$offset) {
                return Factory::response([
                    'count' => 0,
                    'load-previous' => ''
                ]);
            }

            $namespace = Core\Entities::buildNamespace(array_merge([
                'type' => 'activity'
            ], $options));

            $db = Core\Di\Di::_()->get('Database\Cassandra\Indexes');
            $guids = $db->get($namespace, [
                'limit' => 5000,
                'offset' => $offset,
                'reversed' => false
            ]);

            if (isset($guids[$offset])) {
                unset($guids[$offset]);
            }

            if (!$guids) {
                return Factory::response([
                    'count' => 0,
                    'load-previous' => $offset
                ]);
            }

            return Factory::response([
                'count' => count($guids),
                'load-previous' => (string)end(array_values($guids)) ?: $offset
            ]);
        }

        //daily campaign reward
        if (Core\Session::isLoggedIn()) {
            Helpers\Campaigns\HourlyRewards::reward();
        }

        $activity = Core\Entities::get(array_merge(array(
            'type' => 'activity',
            'limit' => get_input('limit', 5),
            'offset' => get_input('offset', '')
        ), $options));
        if (get_input('offset') && !get_input('prepend')) { // don't shift if we're prepending to newsfeed
            array_shift($activity);
        }

        $loadPrevious = (string)current($activity)->guid;

        //   \Minds\Helpers\Counters::incrementBatch($activity, 'impression');

        $disabledBoost = Core\Session::getLoggedinUser()->plus && Core\Session::getLoggedinUser()->disabled_boost;
        //$disabledBoost = true;

        if (get_input('platform') == 'ios') {
            $disabledBoost = true;
        }

        if ($pages[0] == 'network' && !get_input('prepend') && !$disabledBoost && get_input('offset')) { // No boosts when prepending
            try {
                //$limit = isset($_GET['access_token']) || $_GET['offset'] ? 2 : 1;
                $limit = 2;
                $cacher = Core\Data\cache\factory::build('apcu');
                $offset =  $cacher->get(Core\Session::getLoggedinUser()->guid . ':boost-offset:newsfeed');    

                /** @var Core\Boost\Network\Iterator $iterator */
                $iterator = Core\Di\Di::_()->get('Boost\Network\Iterator');
                $iterator->setPriority(!get_input('offset', ''))
                    ->setType('newsfeed')
                    ->setLimit($limit)
                    ->setOffset($offset)
                    //->setRating(0)
                    ->setQuality(0)
                    ->setIncrement(false);


                foreach ($iterator as $boost) {
                    $boost->boosted = true;
                    array_unshift($activity, $boost);
                    //if (get_input('offset')) {
                    //bug: sometimes views weren't being calculated on scroll down
                    Counters::increment($boost->guid, "impression");
                    Counters::increment($boost->owner_guid, "impression");
                    //}
                }
                $cacher->set(Core\Session::getLoggedinUser()->guid . ':boost-offset:newsfeed', $iterator->getOffset(), (3600 / 2));
            } catch (\Exception $e) {
            }

            if (isset($_GET['thumb_guids'])) {
                foreach ($activity as $id => $object) {
                    unset($activity[$id]['thumbs:up:user_guids']);
                    unset($activity[$id]['thumbs:down:user_guid']);
                }
            }
        }

        if ($activity) {
            $response['load-next'] = (string) end($activity)->guid;
            if ($pages[0] == 'featured') {
                $response['load-next'] = (string) end($activity)->featured_id;
            }
            $response['load-previous'] = $loadPrevious;

            if (
                isset($_GET['access_token']) &&
                isset($_GET['platform']) &&
                $_GET['platform'] == 'ios'
            ) {
                $activity = array_filter($activity, function ($activity) {
                    if ($activity->paywall) {
                        return false;
                    }

                    if ($activity->remind_object && $activity->remind_object['paywall']) {
                        return false;
                    }

                    return true;
                });
            }

            if ($pinned_guids) {
                $response['pinned'] = [];
                $entities = Core\Entities::get(['guids' => $pinned_guids]);
                foreach ($entities as $entity) {
                    $exported = $entity->export();
                    $exported['pinned'] = true;
                    $response['pinned'][] = $exported;

                }
//                $response['pinned'] = Factory::Exportable();
            }

            $response['activity'] = factory::exportable($activity, array('boosted'), true);
        }

        return Factory::response($response);
    }

    public function post($pages)
    {
        Factory::isLoggedIn();

        //factory::authorize();
        switch ($pages[0]) {
            case 'remind':
                $embeded = new Entities\Entity($pages[1]);
                $embeded = core\Entities::build($embeded); //more accurate, as entity doesn't do this @todo maybe it should in the future

                //check to see if we can interact with the parent
                if (!Security\ACL::_()->interact($embeded)) {
                    return false;
                }

                Counters::increment($embeded->guid, 'remind');

                if ($embeded->owner_guid != Core\Session::getLoggedinUser()->guid) {
                    Core\Events\Dispatcher::trigger('notification', 'remind', array('to' => array($embeded->owner_guid), 'notification_view' => 'remind', 'title' => $embeded->title, 'entity' => $embeded));
                }

                $message = '';

                if (isset($_POST['message'])) {
                    $message = rawurldecode($_POST['message']);
                }

                /*if ($embeded->owner_guid != Core\Session::getLoggedinUser()->guid) {
                    $cacher = \Minds\Core\Data\cache\Factory::build();
                    if (!$cacher->get(Core\Session::getLoggedinUser()->guid . ":hasreminded:$embeded->guid")) {
                        $cacher->set(Core\Session::getLoggedinUser()->guid . ":hasreminded:$embeded->guid", true);

                        Helpers\Wallet::createTransaction(Core\Session::getLoggedinUser()->guid, 1, $embeded->guid, 'remind');
                        Helpers\Wallet::createTransaction($embeded->owner_guid, 1, $embeded->guid, 'remind');
                    }
                }*/

                $activity = new Activity();
                switch ($embeded->type) {
                    case 'activity':
                        if ($message) {
                            $activity->setMessage($message);
                        }

                        if ($embeded->remind_object) {
                            $activity->setRemind($embeded->remind_object)->save();
                            Counters::increment($embeded->remind_object['guid'], 'remind');
                        } else {
                            $activity->setRemind($embeded->export())->save();
                        }
                        break;
                    default:
                        /**
                         * The following are actually treated as embeded posts.
                         */
                        switch ($embeded->subtype) {
                            case 'blog':
                                if ($embeded->owner_guid == Core\Session::getLoggedInUserGuid()) {
                                    $activity->setTitle($embeded->title)
                                        ->setBlurb(strip_tags($embeded->description))
                                        ->setURL($embeded->getURL())
                                        ->setThumbnail($embeded->getIconUrl())
                                        ->setFromEntity($embeded)
                                        ->setMessage($message)
                                        ->save();
                                } else {
                                    $activity->setRemind((new Activity())
                                        ->setTimeCreated($embeded->time_created)
                                        ->setTitle($embeded->title)
                                        ->setBlurb(strip_tags($embeded->description))
                                        ->setURL($embeded->getURL())
                                        ->setThumbnail($embeded->getIconUrl())
                                        ->setFromEntity($embeded)
                                        ->export())
                                        ->setMessage($message)
                                        ->save();
                                }
                                break;
                            case 'video':
                                if ($embeded->owner_guid == Core\Session::getLoggedInUserGuid()) {
                                    $activity->setFromEntity($embeded)
                                        ->setCustom('video', [
                                            'thumbnail_src' => $embeded->getIconUrl(),
                                            'guid' => $embeded->guid,
                                            'mature' => $embeded instanceof Flaggable ? $embeded->getFlag('mature') : false
                                        ])
                                        ->setTitle($embeded->title)
                                        ->setBlurb($embeded->description)
                                        ->setMessage($message)
                                        ->save();
                                } else {
                                    $activity = new Activity();
                                    $activity->setRemind((new Activity())
                                        ->setTimeCreated($embeded->time_created)
                                        ->setFromEntity($embeded)
                                        ->setCustom('video', [
                                            'thumbnail_src' => $embeded->getIconUrl(),
                                            'guid' => $embeded->guid,
                                            'mature' => $embeded instanceof Flaggable ? $embeded->getFlag('mature') : false
                                        ])
                                        ->setTitle($embeded->title)
                                        ->setBlurb($embeded->description)
                                        ->export())
                                        ->setMessage($message)
                                        ->save();
                                }
                                break;
                            case 'image':
                                if ($embeded->owner_guid == Core\Session::getLoggedInUserGuid()) {
                                    $activity->setCustom('batch', [[
                                        'src' => elgg_get_site_url() . 'fs/v1/thumbnail/' . $embeded->guid,
                                        'href' => elgg_get_site_url() . 'media/' . $embeded->container_guid . '/' . $embeded->guid,
                                        'mature' => $embeded instanceof Flaggable ? $embeded->getFlag('mature') : false,
                                        'width' => $embeded->width,
                                        'height' => $embeded->height,
                                    ]])
                                        ->setFromEntity($embeded)
                                        ->setTitle($embeded->title)
                                        ->setBlurb($embeded->description)
                                        ->setMessage($message)
                                        ->save();
                                } else {
                                    $activity->setRemind((new Activity())
                                        ->setTimeCreated($embeded->time_created)
                                        ->setCustom('batch', [[
                                            'src' => elgg_get_site_url() . 'fs/v1/thumbnail/' . $embeded->guid,
                                            'href' => elgg_get_site_url() . 'media/' . $embeded->container_guid . '/' . $embeded->guid,
                                            'mature' => $embeded instanceof Flaggable ? $embeded->getFlag('mature') : false,
                                            'width' => $embeded->width,
                                            'height' => $embeded->height,
                                        ]])
                                        ->setFromEntity($embeded)
                                        ->setTitle($embeded->title)
                                        ->setBlurb($embeded->description)
                                        ->export())
                                        ->setMessage($message)
                                        ->save();
                                }
                                break;
                        }
                }   

                $event = new Core\Analytics\Metrics\Event();
                $event->setType('action')
                    ->setAction('remind')
                    ->setProduct('platform')
                    ->setUserGuid((string) Core\Session::getLoggedInUser()->guid)
                    ->setEntityGuid((string) $embeded->guid)
                    ->setEntityType($embeded->type)
                    ->setEntitySubtype((string) $embeded->subtype)
                    ->setEntityOwnerGuid((string) $embeded->ownerObj['guid'])
                    ->push();

                $mature_remind =
                    ($embeded instanceof Flaggable ? $embeded->getFlag('mature') : false) ||
                    (isset($embeded->remind_object['mature']) && $embeded->remind_object['mature']);

                $user = Core\Session::getLoggedInUser();
                if (!$user->getMatureContent() && $mature_remind) {
                    $user->setMatureContent(true);
                    $user->save();
                }

                if ($embeded->owner_guid != Core\Session::getLoggedinUser()->guid) {
                    Helpers\Wallet::createTransaction($embeded->owner_guid, 5, $activity->guid, 'Remind');
                }

                return Factory::response(array('guid' => $activity->guid));
                break;
            case 'pin':
                if (isset($pages[1])) {
                    /** @var Activity $activity */
                    $activity = Entities\Factory::build($pages[1]);
                    $user = Core\Session::getLoggedinUser();
                    $user->addPinned($activity->guid);
                    $user->save();

                    Core\Session::regenerate(false);
                    //sync our changes to other sessions
                    (new Core\Data\Sessions())->syncAll($user->guid);
                    return Factory::response([]);
                }
                break;
            case 'unpin':
                if (isset($pages[1])) {
                    /** @var Activity $activity */
                    $activity = Entities\Factory::build($pages[1]);
                    $user = Core\Session::getLoggedinUser();
                    $user->removePinned($activity->guid);
                    $user->save();

                    Core\Session::regenerate(false);
                    //sync our changes to other sessions
                    (new Core\Data\Sessions())->syncAll($user->guid);
                    return Factory::response([]);
                }
                break;

            default:
                //essentially an edit
                if (is_numeric($pages[0])) {
                    $activity = new Activity($pages[0]);
                    if (!$activity->canEdit()) {
                        return Factory::response(array('status' => 'error', 'message' => 'Post not editable'));
                    }

                    $allowed = array('message', 'title');
                    foreach ($allowed as $allowed) {
                        if (isset($_POST[$allowed]) && $_POST[$allowed] !== false) {
                            $activity->$allowed = $_POST[$allowed];
                        }
                    }

                    if (isset($_POST['mature'])) {
                        $activity->setMature($_POST['mature']);
                    }

                    $user = Core\Session::getLoggedInUser();
                    if (!$user->getMatureContent() && isset($_POST['mature']) && $_POST['mature']) {
                        $user->setMatureContent(true);
                        $user->save();
                    }

                    if (isset($_POST['wire_threshold'])) {
                        if (is_array($_POST['wire_threshold']) && ($_POST['wire_threshold']['min'] <= 0 || !$_POST['wire_threshold']['type'])) {
                            return Factory::response([
                                'status' => 'error',
                                'message' => 'Invalid Wire threshold'
                            ]);
                        }

                        $activity->setWireThreshold($_POST['wire_threshold']);
                        $activity->setPaywall(!!$_POST['wire_threshold']);
                    }

                    if (isset($_POST['paywall']) && !$_POST['paywall']) {
                        $activity->setWireThreshold(null);
                        $activity->setPaywall(false);
                    }

                    $activity->setEdited(true);

                    $activity->indexes = ["activity:$activity->owner_guid:edits"]; //don't re-index on edit
                    (new Core\Translation\Storage())->purge($activity->guid);
                    $activity->save();
                    $activity->setExportContext(true);
                    return Factory::response(array('guid' => $activity->guid, 'activity' => $activity->export(), 'edited' => true));
                }

                $activity = new Activity();

                $activity->setMature(isset($_POST['mature']) && !!$_POST['mature']);

                $user = Core\Session::getLoggedInUser();
                if (!$user->getMatureContent() && isset($_POST['mature']) && $_POST['mature']) {
                    $user->setMatureContent(true);
                    $user->save();
                }

                if (isset($_POST['access_id'])) {
                    $activity->access_id = $_POST['access_id'];
                }

                if (isset($_POST['message'])) {
                    $activity->setMessage(rawurldecode($_POST['message']));
                }

                if (isset($_POST['title']) && $_POST['title']) {
                    $activity->setTitle(rawurldecode($_POST['title']))
                        ->setBlurb(rawurldecode($_POST['description']))
                        ->setURL(\elgg_normalize_url(rawurldecode($_POST['url'])))
                        ->setThumbnail(rawurldecode($_POST['thumbnail']));
                }

                if(isset($_POST['wire_threshold']) && $_POST['wire_threshold']) {
                    if (is_array($_POST['wire_threshold']) && ($_POST['wire_threshold']['min'] <= 0 || !$_POST['wire_threshold']['type'])) {
                        return Factory::response([
                            'status' => 'error',
                            'message' => 'Invalid Wire threshold'
                        ]);
                    }

                    $activity->setWireThreshold($_POST['wire_threshold']);
                    $activity->setPayWall(true);
                }

                if (isset($_POST['attachment_guid']) && $_POST['attachment_guid']) {
                    $attachment = entities\Factory::build($_POST['attachment_guid']);
                    if (!$attachment) {
                        break;
                    }
                    $attachment->title = $activity->message;
                    $attachment->access_id = 2;

                    if ($attachment instanceof Flaggable) {
                        $attachment->setFlag('mature', $activity->getMature());
                    }

                    if ($activity->isPaywall()) {
                        $attachment->access_id = 0;
                        $attachment->hidden = true;

                        if (method_exists($attachment, 'setFlag')) {
                            $attachment->setFlag('paywall', true);
                        }

                        if (method_exists($attachment, 'setWireThreshold')) {
                            $attachment->setWireThreshold($activity->getWireThreshold());
                        }
                    }

                    $attachment->save();

                    switch ($attachment->subtype) {
                        case "image":
                            $activity->setCustom('batch', [[
                                'src' => elgg_get_site_url() . 'fs/v1/thumbnail/' . $attachment->guid,
                                'href' => elgg_get_site_url() . 'media/' . $attachment->container_guid . '/' . $attachment->guid,
                                'mature' => $attachment instanceof Flaggable ? $attachment->getFlag('mature') : false,
                                'width' => $attachment->width,
                                'height' => $attachment->height,
                            ]])
                                ->setFromEntity($attachment)
                                ->setTitle($attachment->message);
                            break;
                        case "video":
                            $activity->setFromEntity($attachment)
                                ->setCustom('video', array(
                                    'thumbnail_src' => $attachment->getIconUrl(),
                                    'guid' => $attachment->guid,
                                    'mature' => $attachment instanceof Flaggable ? $attachment->getFlag('mature') : false))
                                ->setTitle($attachment->message);
                            break;
                    }
                }

                $container = null;

                if (isset($_POST['container_guid']) && $_POST['container_guid']) {
                    $activity->container_guid = $_POST['container_guid'];
                    if ($container = Entities\Factory::build($activity->container_guid)) {
                        $activity->containerObj = $container->export();
                    }
                    $activity->indexes = [
                        "activity:container:$activity->container_guid",
                        "activity:network:$activity->owner_guid"
                    ];

                    Core\Events\Dispatcher::trigger('activity:container:prepare', $container->type, [
                        'container' => $container,
                        'activity' => $activity,
                    ]);
                }

                try {
                    $guid = $activity->save();
                } catch (\Exception $e) {
                    return Factory::response([
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ]);
                }

                if ($guid) {
                    if (in_array($activity->custom_type, ['batch', 'video'])) {
                        Helpers\Wallet::createTransaction(Core\Session::getLoggedinUser()->guid, 15, $guid, 'Post');
                    } else {
                        Helpers\Wallet::createTransaction(Core\Session::getLoggedinUser()->guid, 1, $guid, 'Post');
                    }

                    Core\Events\Dispatcher::trigger('social', 'dispatch', array(
                        'entity' => $activity,
                        'services' => array(
                            'facebook' => isset($_POST['facebook']) && $_POST['facebook'] ? $_POST['facebook'] : false,
                            'twitter' => isset($_POST['twitter']) && $_POST['twitter'] ? $_POST['twitter'] : false
                        ),
                        'data' => array(
                            'message' => rawurldecode($_POST['message']),
                            'perma_url' => isset($_POST['url']) ? \elgg_normalize_url(rawurldecode($_POST['url'])) : \elgg_normalize_url($activity->getURL()),
                            'thumbnail_src' => isset($_POST['thumbnail']) ? rawurldecode($_POST['thumbnail']) : null,
                            'description' => isset($_POST['description']) ? rawurldecode($_POST['description']) : null
                        )
                    ));

                    if ($container) {
                        Core\Events\Dispatcher::trigger('activity:container', $container->type, [
                            'container' => $container,
                            'activity' => $activity,
                        ]);
                    }

                    $activity->setExportContext(true);
                    return Factory::response(array('guid' => $guid, 'activity' => $activity->export()));
                } else {
                    return Factory::response(array('status' => 'failed', 'message' => 'could not save'));
                }
        }
    }

    public function put($pages)
    {
        $activity = new Activity($pages[0]);
        if (!$activity->guid) {
            return Factory::response(array('status' => 'error', 'message' => 'could not find activity post'));
        }

        switch ($pages[1]) {
            case 'view':
                try {
                    Core\Analytics\App::_()
                        ->setMetric('impression')
                        ->setKey($activity->guid)
                        ->increment();

                    if ($activity->remind_object) {
                        Core\Analytics\App::_()
                            ->setMetric('impression')
                            ->setKey($activity->remind_object['guid'])
                            ->increment();

                        Core\Analytics\App::_()
                            ->setMetric('impression')
                            ->setKey($activity->remind_object['owner_guid'])
                            ->increment();
                    }

                    Core\Analytics\User::_()
                        ->setMetric('impression')
                        ->setKey($activity->owner_guid)
                        ->increment();
                } catch (\Exception $e) {
                }
                break;
        }

        return Factory::response(array());
    }

    public function delete($pages)
    {
        $activity = new Activity($pages[0]);

        if (!$activity->guid) {
            return Factory::response(array('status' => 'error', 'message' => 'could not find activity post'));
        }

        if (!$activity->canEdit()) {
            return Factory::response(array('status' => 'error', 'message' => 'you don\'t have permission'));
        }

        /** @var Entities\User $owner */
        $owner = $activity->getOwnerEntity();

        if (
            $activity->entity_guid &&
            in_array($activity->custom_type, ['batch', 'video'])
        ) {
            // Delete attachment object
            try {
                $attachment = Entities\Factory::build($activity->entity_guid);

                if ($attachment && $owner->guid == $attachment->owner_guid) {
                    $attachment->delete();
                }
            } catch (\Exception $e) {
                error_log("Cannot delete attachment: {$activity->entity_guid}");
            }
        }

        // remove from pinned
        $owner->removePinned($activity->guid);

        if ($activity->delete()) {
            if ($activity->remind_object && $activity->remind_object['owner_guid'] != Core\Session::getLoggedinUser()->guid) {
                Helpers\Wallet::createTransaction($activity->remind_object['owner_guid'], -5, $activity->remind_object['guid'], 'Remind Removed');
            } elseif (!$activity->remind_object) {
                if (in_array($activity->custom_type, ['batch', 'video'])) {
                    Helpers\Wallet::createTransaction($activity->owner_guid, -15, $activity->guid, 'Post Removed');
                } else {
                    Helpers\Wallet::createTransaction($activity->owner_guid, -1, $activity->guid, 'Post Removed');
                }

            }

            return Factory::response(array('message' => 'removed ' . $pages[0]));
        }

        return Factory::response(array('status' => 'error', 'message' => 'could not delete'));
    }
}
