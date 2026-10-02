<?php
declare(strict_types=1);

namespace App\Service;

/** Send one SMTP message per address and never hide a partial outcome as success. */
final class RouteExecutorReviewIndividualMail
{
    /** @return array{status:string,recipients:array<string,string>} */
    public static function send(DocumentMailTransport $transport, array $credentials, array $message): array
    {
        $addresses=array_values(array_unique(array_map(DocumentMailMime::address(...),$message['to'])));
        if (!$addresses) throw new \InvalidArgumentException('Укажите получателей письма.');
        $outcomes=[];
        foreach ($addresses as $address) {
            $individual=$message;
            $individual['to']=[$address];
            $individual['cc']=[];
            $individual['message_id']=self::messageId((string)$message['message_id'],$address);
            DocumentMailMime::build($individual);
            $outcomes[$address]=$transport->send($credentials,$individual);
        }
        $statuses=array_values($outcomes);
        $status=count(array_filter($statuses,static fn(string $value):bool=>$value==='accepted'))===count($statuses)
            ? 'accepted'
            : (count(array_filter($statuses,static fn(string $value):bool=>$value==='failed'))===count($statuses)
                ? 'failed':'unknown');
        return ['status'=>$status,'recipients'=>$outcomes];
    }

    private static function messageId(string $base,string $address): string
    {
        DocumentMailMime::messageId($base);
        $at=strrpos($base,'@');
        $id=substr($base,0,$at).'-'.substr(hash('sha256',$address),0,16).substr($base,$at);
        return DocumentMailMime::messageId($id);
    }
}
