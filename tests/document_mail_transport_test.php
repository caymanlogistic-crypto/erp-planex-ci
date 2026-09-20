<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/Service/DocumentMailTransport.php';
use App\Service\DocumentMailMime;
use App\Service\DocumentMailTransport;
$ok=static function(bool $condition,string $message):void { if (!$condition) throw new RuntimeException($message); };
$message=['from'=>'assistant@one.test','to'=>['carrier@example.test'],'cc'=>['logist@one.test'],'message_id'=>'<request.123@one.test>',
    'subject'=>'Документы на рейс №123 Мурманск','text'=>"Просим документы.\nОтветить всем.",
    'attachments'=>[['name'=>'Паспорт_Иванов.pdf','mime'=>'application/pdf','bytes'=>"%PDF-1.4\nsynthetic"],['name'=>'СТС_Тягач.png','mime'=>'image/png','bytes'=>"synthetic-image"]]];
$raw=DocumentMailMime::build($message);
$parsed=DocumentMailMime::parse($raw);
$ok($parsed['subject']===$message['subject'],'UTF-8 subject roundtrip');
$ok(count($parsed['attachments'])===2,'Multiple attachments lost');
foreach ($message['attachments'] as $i=>$file) {
    $ok($parsed['attachments'][$i]['name']===$file['name'],'Attachment filename changed');
    $ok($parsed['attachments'][$i]['bytes']===$file['bytes'],'Attachment bytes changed');
}
$ok(str_contains($parsed['text'],'Ответить всем.'),'Text lost');
$html=DocumentMailMime::parse("From: carrier@example.test\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<script>steal()</script><p>Документы позже</p><img src=https://tracker.test/x>");
$ok(!str_contains($html['text'],'steal') && !str_contains($html['text'],'<') && str_contains($html['text'],'Документы позже'),'Unsafe HTML retained');
$bad=$message; $bad['subject']="Trip\r\nBcc: intruder@example.test";
try {DocumentMailMime::build($bad); throw new RuntimeException('Header injection accepted');} catch (InvalidArgumentException) {}
$bad=$message; $bad['to']=["bad@example.test\r\nRCPT TO:<x@example.test>"];
try {DocumentMailMime::build($bad); throw new RuntimeException('Recipient injection accepted');} catch (InvalidArgumentException) {}
// In-memory protocol transcript exercises the actual send method without any network calls.
class ScriptedDocumentTransport extends DocumentMailTransport
{
    public array $writes=[];
    public function __construct(private array $responses) {}
    protected function smtpLogin(#[SensitiveParameter] array $credentials) {return fopen('php://temp','r+');}
    protected function line($socket):string {return array_shift($this->responses)??throw new RuntimeException('Simulated disconnect');}
    protected function write($socket,#[SensitiveParameter] string $data):void {$this->writes[]=$data;}
}
$credentials=['email'=>'assistant@one.test','password'=>'synthetic','settings'=>['imap_host'=>'imap.example.test','imap_port'=>993,'smtp_host'=>'smtp.example.test','smtp_port'=>465,'encryption'=>'SSL']];
$accepted=new ScriptedDocumentTransport(["250 OK\r\n","250 OK\r\n","250 OK\r\n","354 go\r\n","250 queued\r\n"]);
$ok($accepted->send($credentials,$message)==='accepted','SMTP acknowledgement lost');
$ok(str_contains(implode('',$accepted->writes),'RCPT TO:<logist@one.test>'),'Logist copy omitted');
$failed=new ScriptedDocumentTransport(["250 OK\r\n","550 reject\r\n"]);
$ok($failed->send($credentials,$message)==='failed','Rejected recipient marked accepted');
$ok(!str_contains(implode('',$failed->writes),"DATA\r\n"),'Sent body after recipient rejection');
$unknown=new ScriptedDocumentTransport(["250 OK\r\n","250 OK\r\n","250 OK\r\n","354 go\r\n"]);
$ok($unknown->send($credentials,$message)==='unknown','Lost acknowledgement not distinguished from safe failure');
echo "DOCUMENT_MAIL_TRANSPORT=PASS\n";

$ok(str_ends_with(DocumentMailMime::filename(str_repeat('А',200).'.pdf'),'.pdf'),'Long filename lost actual extension');
$continued=DocumentMailMime::parse("Content-Type: application/pdf\r\nContent-Disposition: attachment; filename*0*=UTF-8''%D0%9F%D0%B0; filename*1*=%D1%81%D0%BF%D0%BE%D1%80%D1%82.pdf\r\n\r\nPDF");
$ok($continued['attachments'][0]['name']==='Паспорт.pdf','RFC2231 filename continuation lost');
try {DocumentMailMime::parse("Content-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\n\r\nincomplete");throw new LogicException('Malformed multipart silently lost data');}catch(RuntimeException){}

class ScriptedImapTransport extends DocumentMailTransport
{
    public array $writes=[];
    public function __construct(private string $transcript) {}
    protected function connect(string $host,int $port) {$socket=fopen('php://temp','r+');fwrite($socket,$this->transcript);rewind($socket);return $socket;}
    protected function write($socket,#[SensitiveParameter] string $data):void {$this->writes[]=$data;}
}
$greeting="* OK ready\r\na1 OK logged in\r\n* OK [UIDVALIDITY 42] stable\r\n* OK [UIDNEXT 103] next\r\na2 OK selected\r\n";
$frontier=new ScriptedImapTransport($greeting);
$cursor=$frontier->poll($credentials,null,static function():void{throw new LogicException('Historical mail imported');});
$ok($cursor===['uidvalidity'=>42,'uid'=>102] && !str_contains(implode('',$frontier->writes),'FETCH'),'Initial cursor read historical mail');
$head1="Message-ID: <unrelated@example.test>\r\nSubject: personal\r\n\r\n";
$head2="Message-ID: <reply@example.test>\r\nIn-Reply-To: <request.123@one.test>\r\n\r\n";
$literal=static fn(string $tag,string $raw,int $size):string=>"* 1 FETCH (RFC822.SIZE $size BODY[] {".strlen($raw)."}\r\n".$raw.")\r\n$tag OK fetched\r\n";
$reader=new ScriptedImapTransport($greeting."* SEARCH 101 102\r\na3 OK found\r\n".$literal('h0',$head1,1000).$literal('h1',$head2,strlen($raw)).$literal('b1',$raw,strlen($raw)));
$received=[];
$cursor=$reader->poll($credentials,['uidvalidity'=>42,'uid'=>100],static function(array $headers,callable $fetch)use(&$received):void{if(isset($headers['in-reply-to']))$received[]=$fetch();});
$ok($cursor['uid']===102 && count($received)===1 && count($received[0]['attachments'])===2,'Targeted IMAP import failed');
$ok(!str_contains(implode('',$reader->writes),'UID FETCH 101 (BODY.PEEK[])'),'Unrelated message body exposed');
try {(new ScriptedImapTransport($greeting))->poll($credentials,['uidvalidity'=>41,'uid'=>100],static fn()=>null);throw new LogicException('UID reset ignored');}catch(RuntimeException){}
echo "DOCUMENT_MAIL_IMAP=PASS\n";
