<?php
/**
 * API for downloading the wire's ledger as a CSV
 */

namespace Minds\Controllers\api\v1\wire;

use Minds\Api\Factory;
use Minds\Core;
use Minds\Core\Di\Di;

class ledger
{
    public function get($pages)
    {
        $actor_guid = isset($pages[0]) ? $pages[0] : Core\Session::getLoggedInUser()->guid;
        $repo = Di::_()->get('Wire\Repository');
        $type = isset($_GET['type']) ? $_GET['type'] : 'received';
        $start = isset($_GET['start']) ? ((int) $_GET['start']) : (new \DateTime('midnight'))->modify("-30 days")->getTimestamp();

        $timeframe = [
            'start' => $start,
            'end' => time()
        ];

        switch ($type) {
            case 'sent':
                $result = $repo->getWiresBySender($actor_guid, 'money', $timeframe, [
                    'page_size' => 1000
                ]);
                break;

            case 'received':
                $result = $repo->getWiresByReceiver($actor_guid, 'money', $timeframe, [
                    'page_size' => 1000
                ]);
                break;

            default:
                return Factory::response([
                    'status' => 'error',
                    'message' => 'Unknown type'
                ]);
        }

        $file = 'ledger_' . date('Y_m_d_H_i_s');
        $csv = fopen('php://temp/maxmemory:' . (10 * 1024 * 1024), 'r+');

        fputcsv($csv, [
            'email',
            'sent date',
            'currency',
            'amount'
        ]);

        foreach ($result['wires'] as $wire) {
            $user = $wire->getFrom();

            fputcsv($csv, [
                $user->getEmail(),
                date('Y-m-d H:i:s', $wire->getTimeCreated()),
                $wire->getMethod(),
                $wire->getAmount()
            ]);
        }

        header('Content-type: text/csv');
        header('Content-Disposition: attachment; filename="' . $file . '.csv"');
        rewind($csv);
        fpassthru($csv);
        exit;
    }

    public function post($pages)
    {
        return Factory::response([]);
    }

    public function put($pages)
    {
        return Factory::response([]);
    }

    public function delete($pages)
    {
        return Factory::response([]);
    }
}