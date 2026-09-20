<?php
declare(strict_types=1);

// Invoked by the guarded Retry MySQL test, using its migrated, disposable DB.
return static function (PDO $pdo, bool $withMaxIds): void {
    $ok = static function (bool $value, string $message): void {
        if (!$value) throw new RuntimeException($message);
    };
    foreach (['max_event_deliveries','max_message_logs','feo_placement_current',
        'feo_placement_block_requests','feo_placement_blocks','waste_trip_requests',
        'waste_trips','feo_requests','users','company_memberships','user_accounts'] as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
    $pdo->exec("INSERT INTO user_accounts VALUES (10,'ERP Сидоров',NULL),(11,'ERP Гладких','222222')");
    $pdo->exec($withMaxIds
        ? "UPDATE user_accounts SET max_user_id='111111' WHERE id=10"
        : 'UPDATE user_accounts SET max_user_id=NULL');
    $pdo->exec("INSERT INTO company_memberships VALUES (20,10,7),(21,11,7)");
    $pdo->exec("INSERT INTO users VALUES (30,20,'ERP Сидоров'),(31,21,'ERP Гладких')");
    $trip = $pdo->prepare('INSERT INTO waste_trips(id,business_id,comment,status,responsible_user_id) VALUES(?,?,?, ?,?)');
    foreach ([253=>30,258=>31,268=>30,274=>31,279=>30,281=>31,256=>30] as $route=>$manager) {
        $trip->execute([$route,$route,'QA '.$route,App\Service\WasteTripService::STATUS_IN_PROGRESS,$manager]);
    }
    // Owner's exact IDs/masses/relationships, with synthetic coordinates and MAX identities.
    $rows = [
        [247370,150,44,37,253],[248948,154,44.1,37,253],[249111,60,44.2,37,253],
        [249071,210,47,40,258],[253319,234,47.1,40,258],
        [290075,100,47.2,40,258],[294002,100,47.3,40,258],[308396,100,47.3,40,258],
        [315648,100,68,33,274],[900268,100,68.001,33,268],
        [900279,100,56.5,84.9,279],[900281,100,56.506,84.9,281],
        [312411,100,56,101,256],[313751,100,56,101,256],
        [248516,60,44,37,0],[113957,108,60,50,0],[237440,331,68,33,0],
        [228210,164,47.2,40,0],[266289,2078,47.3,40,0],
        [302489,2108,56.50013,84.9,0],[300715,709,56.50014,84.9,0],[300864,510,56.50015,84.9,0],
        [276494,2500,56.503,84.9,0],[226681,24,56,101,0],
    ];
    // Put the second route on the other side of the 5km boundary from T5,
    // while keeping T6 within 5km of both routes.
    foreach ($rows as &$row) {
        if ($row[0] === 900281) $row[2] = 56.546;
        if ($row[0] === 276494) $row[2] = 56.523;
    }
    unset($row);
    $insert = $pdo->prepare('INSERT INTO feo_requests VALUES(?,?,?,?,?)');
    $link = $pdo->prepare('INSERT INTO waste_trip_requests(waste_trip_id,feo_request_id) VALUES(?,?)');
    $baselineIds = [];
    foreach ($rows as [$id,$mass,$lat,$lng,$route]) {
        $insert->execute([$id,(string)$id,$mass/1000,(string)$lat,(string)$lng]);
        if ($route) { $link->execute([$route,$id]); $baselineIds[] = (string)$id; }
    }
    $sent = []; $memberLookups = 0;
    $messaging = new App\Service\MaxMessagingService($pdo, static function (string $token) use (&$sent,&$memberLookups): App\Service\MaxApiClient {
        return new App\Service\MaxApiClient($token, static function (string $method,string $url,?array $body) use (&$sent,&$memberLookups): array {
            if (str_contains($url,'/members?user_ids=')) {
                $memberLookups++;
                $id = str_contains($url,'user_ids=111111') ? 111111 : 222222;
                return ['members'=>[['user_id'=>$id,'first_name'=>'MAX','last_name'=>(string)$id]]];
            }
            if (str_contains($url,'/chats/')) return ['type'=>'chat','status'=>'active','title'=>'QA'];
            $sent[] = $body;
            return ['message'=>['body'=>['mid'=>'owner-'.count($sent)]]];
        });
    });
    $events = new App\Service\MaxEventNotificationService($pdo,$messaging);
    $placement = new App\Service\FeoPlacementService();
    $routes = new App\Service\FeoPlacementRouteNotificationService();
    $key = 'feo.placement.block_created';
    $preview = $placement->previewCreate($pdo,$baselineIds,'addition');
    $placement->createBlock($pdo,['ids'=>$baselineIds,'block_type'=>'addition'], $preview['state_hash']);
    $snapshot = static fn(): array => $pdo->query('SELECT zayavka_id,source_block_id FROM feo_placement_current ORDER BY zayavka_id')->fetchAll(PDO::FETCH_ASSOC);
    $baseline = $snapshot();
    $baselineMass = (float)$placement->currentSummary($pdo)['total_mass_kg'];
    $cases = [
        [1,'addition',['248516'],60,['same_point:253']],
        [2,'addition',['113957'],108,[]],
        [3,'addition',['237440'],331,['same_point:274']],
        [4,'addition',['228210','266289'],2242,['same_point:258']],
        [5,'addition',['302489','300715','300864'],3327,['nearby:279']],
        [6,'addition',['276494'],2500,['nearby:279','nearby:281']],
        [7,'exclusion',['247370','248948','249111','249071','253319'],808,['active_exclusion:253','active_exclusion:258']],
        [8,'addition',['226681'],24,['same_point:256']],
    ];
    foreach ($cases as [$number,$type,$ids,$mass,$expected]) {
        $start = count($sent);
        $main = null; $supp = []; $actual = [];
        $preview = $placement->previewCreate($pdo,$ids,$type);
        $created = $placement->createBlock($pdo,['name'=>'Owner T'.$number,'ids'=>$ids,'block_type'=>$type],$preview['state_hash'],
            static function (array $row) use ($events,$routes,$pdo,$key,$type,$ids,&$main,&$supp,&$actual): void {
                $main = $events->enqueue($key,(string)$row['block_id'],[
                    'action_title'=>$type==='addition'?'ДОБАВЛЕНО НА КАРТУ':'СНЯТИЕ ЗАЯВОК',
                    'request_count'=>(string)$row['preview']['selected_count'],
                    'mass_kg'=>$routes::massText((float)$row['preview']['selected_mass_kg']),
                ],77);
                foreach ($routes->intents($pdo,$pdo,7,$type,$ids) as $intent) {
                    $actual[] = $intent['kind'].':'.$intent['route_id'];
                    $entity = sprintf('b%d:%s:r%d:p%d',$row['block_id'],$intent['kind'],$intent['route_id'],$intent['part']);
                    $supp[] = $events->enqueuePrepared($key,$entity,$intent['text'],$intent['format'],77,$intent['error']??null);
                }
            });
        $ok($actual===$expected,'T'.$number.' supplemental grouping/order');
        $sign = $type==='addition'?1:-1;
        $ok(count($snapshot())===count($baseline)+$sign*count($ids),'T'.$number.' available request count');
        $ok(abs((float)$created['current']['total_mass_kg']-($baselineMass+$sign*$mass))<0.001,'T'.$number.' available mass');
        $ok($events->dispatch((int)$main)['status']==='sent','T'.$number.' main sent');
        foreach ($supp as $id) $ok($events->dispatch((int)$id,true)['status']==='sent','T'.$number.' supplemental sent');
        $ok(count($sent)-$start===1+count($expected),'T'.$number.' exact message count');
        $heading = $type==='addition'?'ДОБАВЛЕНО НА КАРТУ':'СНЯТИЕ ЗАЯВОК';
        $ok($sent[$start]['text']===$heading.":\n\nЗаявок: ".count($ids).', масса: '.$routes::massText((float)$mass).' кг','T'.$number.' main text');
        foreach (array_slice($sent,$start+1) as $message) {
            $ok($withMaxIds
                ? str_contains($message['text'],'[MAX ') && str_contains($message['text'],'](max://user/')
                : !str_contains($message['text'],'max://user/') && !str_contains($message['text'],'ERP '),
                'T'.$number.' optional profile mention');
            foreach ($ids as $id) $ok(substr_count($message['text'],$id)<=1,'T'.$number.' duplicate request pair');
        }
        if ($number===4 || $number===5) {
            foreach ($ids as $id) $ok(str_contains($sent[$start+1]['text'],$id),'T'.$number.' grouped request missing');
        }
        if ($number===5) $ok(str_contains($sent[$start+1]['text'],'3 327 кг'),'T5 group mass');
        if ($number===7) {
            $ok(str_contains($sent[$start+1]['text'],'Всего: 3, масса: 364 кг'),'T7 first group');
            $ok(str_contains($sent[$start+2]['text'],'Всего: 2, масса: 444 кг'),'T7 second group');
        }
        $messageCount = count($sent);
        if ($number===1 && !$withMaxIds) {
            $pdo->exec("UPDATE user_accounts SET max_user_id='111111' WHERE id=10");
            $terminal = $events->rebuildFeoSupplementalForRetry((int)$supp[0],$pdo,7);
            $ok($terminal['status']==='sent' && !str_contains($terminal['message_text'],'max://user/'),'adding ID after delivery must not rewrite or resend');
            $pdo->exec('UPDATE user_accounts SET max_user_id=NULL WHERE id=10');
        }
        foreach (array_merge([$main],$supp) as $id) $events->dispatch((int)$id,true);
        $ok(count($sent)===$messageCount,'T'.$number.' sent retry duplicates');
        $deliveries = (int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn();
        $disable = $placement->previewStatusChange($pdo,(int)$created['block_id'],'disabled');
        $placement->changeStatus($pdo,(int)$created['block_id'],'disabled',$disable['state_hash']);
        $ok($snapshot()===$baseline,'T'.$number.' full baseline restored, including source blocks');
        $ok((int)$pdo->query('SELECT COUNT(*) FROM max_event_deliveries')->fetchColumn()===$deliveries && count($sent)===$messageCount,'T'.$number.' disable must not notify');
        echo 'FEO_OWNER_SCENARIO_'.($withMaxIds?'MENTION_':'NO_ID_').$number."=PASS\n";
    }
    $ok(count($sent)===17,'all eight scenarios produce exactly 17 messages');
    $ok($memberLookups===($withMaxIds?9:0),'unaddressed delivery must not perform MAX member lookup');
};
