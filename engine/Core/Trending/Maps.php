<?php
namespace Minds\Core\Trending;

class Maps
{

    public static $maps = [
        'videos' => [
            'type' => 'object',
            'subtype' => 'video',
            'aggregates' => [
                Aggregates\Comments::class,
                Aggregates\Votes::class,
                Aggregates\Reminds::class
            ]
        ],
        'images' => [
            'type' => 'object',
            'subtype' => 'image',
            'aggregates' => [
                Aggregates\Comments::class,
                Aggregates\Votes::class,
                Aggregates\Reminds::class
            ]
        ],
        'blogs' => [
            'type' => 'object',
            'subtype' => 'blog',
            'aggregates' => [
                Aggregates\Comments::class,
                Aggregates\Votes::class,
                Aggregates\Reminds::class
            ]
        ],
        'newsfeed' => [
            'type' => 'activity',
            'subtype' => '',
            'aggregates' => [
                Aggregates\Comments::class,
                Aggregates\Votes::class,
                Aggregates\Reminds::class
            ]
        ],
        'channels' => [
            'type' => 'user',
            'subtype' => '',
            'aggregates' => [
                Aggregates\ChannelVotes::class,
                Aggregates\Subscriptions::class
            ]
        ]
    ];

}
