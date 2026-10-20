<?php
declare(strict_types=1);

namespace App\Service;

require_once __DIR__ . '/RouteExecutorExistingEntityService.php';

use App\Core\Database;
use DomainException;
use PDO;

/** Moves an accepted full review card into the ordinary company directories once. */
final class RouteExecutorReviewMaterializationService
{
    public function __construct(
        private readonly PDO $tenant,
        private readonly Database $central,
        private readonly array $config,
        private readonly int $companyId,
    ) {}

    /** Check the exact combined-create input before any final email is sent. No records are written. */
    public function validateBeforeFinalSend(array $card, int $userId, string $role): void
    {
        $cards = new RouteExecutorReviewCardService($this->tenant, $this->companyId);
        $uploads = $cards->storedFiles($card, false);
        [$post, $files] = self::standardInput($card['snapshot'], $uploads);
        $post['existing'] = (array) ($card['snapshot']['selected_entities'] ?? []);
        RouteExecutorCombinedCreateService::create(
            $this->tenant, $this->central, $this->config, $this->companyId,
            $post, $files, $userId, $role, null, true, true
        );
    }

    public function materialize(string $cardId, int $userId, string $role): int
    {
        $lockName='route-review-materialize:'.$cardId;
        $q=$this->tenant->prepare('SELECT GET_LOCK(?,5)');$q->execute([$lockName]);
        if ((int)$q->fetchColumn()!==1) throw new DomainException('Карточка уже переносится в справочники. Повторите позже.');
        try {
            $cards=new RouteExecutorReviewCardService($this->tenant,$this->companyId);
            $card=$cards->load($cardId);
            if (!$card) throw new DomainException('Карточка уже внесена в справочники или удалена. Обновите страницу.');
            if ((string)$card['stage']!=='final') throw new DomainException('В справочники переносится только отправленный полный комплект.');
            $stored=$cards->storedFiles($card);
            $package=RouteExecutorReviewPackage::inspect($card['snapshot'],$stored,true);
            if (!$package['full']) throw new DomainException('Карточка не содержит полный комплект данных.');
            $uploads=$cards->storedFiles($card,false);
            [$post,$files]=self::standardInput($card['snapshot'],$uploads);
            $post['existing']=(array)($card['snapshot']['selected_entities']??[]);
            $removed=array_map('intval',(array)($card['snapshot']['removed_documents']??[]));
            $uploadedKeys=array_fill_keys(array_column($uploads,'key'),true);
            $library=new RouteExecutorExistingEntityService($this->tenant,$this->companyId);
            foreach ($post['existing'] as $kind=>$id) foreach ($library->detail((string)$kind,(int)$id)['files'] as $document) {
                if (isset($uploadedKeys[$document['formId'].'|'.$document['field']])) $removed[]=(int)$document['id'];
            }
            $post['existing_removed_documents']=array_values(array_unique($removed));
            $finish=function (int $crewId) use ($cardId): void {
                $q=$this->tenant->prepare("UPDATE route_executor_review_cards SET deleted_at=NOW(6),updated_at=NOW(6) WHERE card_id=? AND deleted_at IS NULL AND EXISTS (SELECT 1 FROM route_executor_review_sends s WHERE s.card_id=route_executor_review_cards.card_id AND s.stage='final' AND s.final_mail_status='accepted')");
                $q->execute([$cardId]);
                if ($q->rowCount()!==1) throw new DomainException('Состояние карточки изменилось. Обновите страницу.');
            };
            return RouteExecutorCombinedCreateService::create($this->tenant,$this->central,$this->config,$this->companyId,$post,$files,$userId,$role,$finish,true);
        } finally {
            $this->tenant->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        }
    }

    /** Recreates the same nested input accepted by the ordinary combined-create path. */
    public static function standardInput(array $snapshot,array $storedFiles): array
    {
        $groups=['lab-contractor-form'=>'contractor','lab-driver-form'=>'driver','lab-vehicle-form'=>'vehicle'];
        $queries=['contractor'=>[],'driver'=>[],'vehicle'=>[]];
        foreach ((array)($snapshot['controls']??[]) as $control) {
            if (!is_array($control)) continue;
            $group=$groups[(string)($control['formId']??'')]??null;
            if ($group===null) continue;
            if (in_array((string)($control['type']??''),['checkbox','radio'],true) && empty($control['checked'])) continue;
            $name=(string)($control['name']??'');
            if (!preg_match('/^[a-zA-Z0-9_\[\]-]{1,100}$/D',$name)) throw new DomainException('Некорректное поле карточки.');
            $queries[$group][]=rawurlencode($name).'='.rawurlencode((string)($control['value']??''));
        }
        $post=[];
        foreach ($queries as $group=>$query) parse_str(implode('&',$query),$post[$group]);
        $post['responsible_logist_membership_id']=(string)($snapshot['logist']??'');
        $files=[];
        foreach ($storedFiles as $stored) {
            $key=(string)($stored['key']??'');
            [$form,$field]=array_pad(explode('|',$key,2),2,'');
            $group=$groups[$form]??null;
            if ($group===null || !preg_match('/^(predef_doc|custom_doc_file)((?:\[[a-zA-Z0-9_-]*\]){1,3})$/D',$field,$match)) throw new DomainException('Некорректный документ карточки.');
            preg_match_all('/\[([^\]]*)\]/',$match[2],$parts);
            $path=$parts[1];
            $bucket=$group.'_'.$match[1];
            // An implicit custom [] identifies the label row, whose empty upload
            // controls still count. Preserve sparse row positions instead of compacting.
            $implicitCustomRow=$match[1]==='custom_doc_file' && end($path)==='' &&
                (count($path)===1 || ($group==='vehicle' && count($path)===2 && in_array($path[0],['primary','secondary'],true)));
            if ($implicitCustomRow) $path[count($path)-1]=(string)($stored['index']??0);
            // Stored index identifies the browser control, not a member of its FileList.
            // Historical cards therefore legitimately contain repeated indices. Rebuild
            // file-list [] in source order, keeping custom row/label indices intact.
            $leaf=array_pop($path);
            $names=&$files[$bucket]['name'];
            foreach ($path as $segment) {
                if ($segment==='' || (isset($names[$segment]) && !is_array($names[$segment]))) throw new DomainException('Некорректный документ карточки.');
                $names=&$names[$segment];
            }
            if ($names!==null && !is_array($names)) throw new DomainException('Некорректный документ карточки.');
            if ($leaf==='') {
                $names??=[];
                $names[]=null;
                $leaf=array_key_last($names);
            } elseif (array_key_exists($leaf,$names??[])) {
                throw new DomainException('Повторяется одиночное поле документа карточки.');
            }
            $path[]=$leaf;
            unset($names);
            foreach (['name'=>(string)$stored['name'],'type'=>(string)($stored['mime']??'application/octet-stream'),
                'tmp_name'=>(string)$stored['tmp_name'],'error'=>(int)$stored['error'],'size'=>(int)$stored['size']] as $attribute=>$value) {
                $target=&$files[$bucket][$attribute];
                foreach ($path as $segment) $target=&$target[$segment];
                $target=$value;
                unset($target);
            }
        }
        return [$post,$files];
    }
}
