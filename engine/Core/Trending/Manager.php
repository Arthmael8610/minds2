<?php
namespace Minds\Core\Trending;

use Cassandra;
use Cassandra\Varint;
use Minds\Core\Data\Cassandra\Client;
use Minds\Core\Data\Cassandra\Prepared\Custom;
use Minds\Core\Di\Di;

class Manager
{

    private $repository;
    private $validator;

    private $entities = [];
    private $from;
    private $to;

    public function __construct($repository = null, $validator = null, $maps = null)
    {
        $this->repository = $repository ?: Di::_()->get('Trending\Repository');
        $this->validator = $validator ?: new EntityValidator;
        $this->maps = $maps ?: Maps::$maps;

        $this->from = strtotime('-24 hours') * 1000;
        $this->to = time() * 1000;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function setTo($to)
    {
        $this->to = $to;
        return $this;
    }

    public function run()
    {
        $ratings = [1, 2];
        foreach ($ratings as $rating) {
            foreach ($this->maps as $key => $map) {
                $entities = [];
                foreach ($map['aggregates'] as $aggregate) {
                    $class = is_string($aggregate) ? new $aggregate : $aggregate;
                    $class->setLimit(100);
                    $class->setType($map['type']);
                    $class->setSubtype($map['subtype']);
                    $class->setFrom($this->from);
                    $class->setTo($this->to);

                    foreach ($class->get() as $guid => $score) {
                        if (!$this->validator->isValid($guid, $map['type'], $map['subtype'], $rating)) {
                            echo "\n[{$map['type']} $guid is not valid";
                            continue;
                        }
                        //initialize the new guid
                        if (!isset($entities[$guid])) {
                            $entities[$guid] = 0;
                        }
                        $entities[$guid] += $score;
                    }
                }

                arsort($entities);
                $guids = [];
                foreach($entities as $guid => $score) {
                    $guids[] = $guid;
                    echo "\n$key: $guid ($score)";
                }

                $this->repository->add($key, $guids, $rating);
            }
        }
    }

}
