<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/app/Service/RouteExecutorReviewIndividualMail.php';
require_once dirname(__DIR__).'/app/Service/DocumentMailTransport.php';

use App\Service\DocumentMailTransport;
use App\Service\RouteExecutorReviewIndividualMail;

final class IndividualMailStub extends DocumentMailTransport
{
    public array $messages=[];
    public function __construct(private array $results) {}
    public function send(#[\SensitiveParameter] array $credentials,array $message): string
    {
        $this->messages[]=$message;
        return array_shift($this->results);
    }
}

$message=['from'=>'sender@example.test','to'=>['first@example.test','second@example.test','FIRST@example.test'],
    'message_id'=>'<route-executor-card-final@example.test>','subject'=>'Full package','text'=>'Example','html'=>'<p>Example</p>'];
$transport=new IndividualMailStub(['accepted','accepted']);
$result=RouteExecutorReviewIndividualMail::send($transport,[],$message);
if ($result['status']!=='accepted' || count($transport->messages)!==2
    || array_column($transport->messages,'to')!==[['first@example.test'],['second@example.test']]
    || $transport->messages[0]['message_id']===$transport->messages[1]['message_id']
    || $transport->messages[0]['message_id']===$message['message_id']) {
    throw new RuntimeException('Each distinct address must receive its own message and Message-ID.');
}
$transport=new IndividualMailStub(['accepted','failed']);
$result=RouteExecutorReviewIndividualMail::send($transport,[],$message);
if ($result['status']!=='unknown' || $result['recipients']!==[
    'first@example.test'=>'accepted','second@example.test'=>'failed',
]) throw new RuntimeException('Mixed outcome must not enable an automatic retry.');
$transport=new IndividualMailStub(['failed','failed']);
if (RouteExecutorReviewIndividualMail::send($transport,[],$message)['status']!=='failed') {
    throw new RuntimeException('Only all-failed delivery is safe to retry.');
}
echo "route_executor_individual_mail_test: PASS\n";
