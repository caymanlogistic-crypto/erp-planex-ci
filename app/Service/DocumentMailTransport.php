<?php
declare(strict_types=1);
namespace App\Service;

require_once __DIR__.'/EmployeeMailTransport.php';
require_once __DIR__.'/DocumentMailMime.php';

/** Real correspondence reuses the configured, TLS-verified employee transport. */
class DocumentMailTransport extends EmployeeMailTransport
{
    /** A lost final SMTP acknowledgement is unknown and must never be retried automatically. */
    public function send(#[\SensitiveParameter] array $credentials,array $message): string
    {
        $credentials['settings']=EmployeeMailSettingsService::validate($credentials['settings']??[]);
        if (DocumentMailMime::address($credentials['email'])!==DocumentMailMime::address($message['from'])) throw new \DomainException('Адрес отправителя изменился. Проверьте письмо заново.');
        $raw=DocumentMailMime::build($message);
        $recipients=array_unique(array_merge($message['to'],$message['cc']??[]));
        $socket=null;
        $dataStarted=false;
        try {
            $socket=$this->smtpLogin($credentials);
            $this->write($socket,'MAIL FROM:<'.DocumentMailMime::address($message['from']).">\r\n"); $this->expect($socket,250);
            foreach ($recipients as $address) {
                $this->write($socket,'RCPT TO:<'.DocumentMailMime::address($address).">\r\n"); $this->expect($socket,250);
            }
            $this->write($socket,"DATA\r\n"); $this->expect($socket,354);
            $dataStarted=true;
            $this->write($socket,preg_replace('/(?m)^\./','..',$raw)."\r\n.\r\n");
            $this->expect($socket,250);
            // QUIT failure cannot undo the server's acceptance.
            try { $this->write($socket,"QUIT\r\n"); } catch (\Throwable) {}
            return 'accepted';
        } catch (\Throwable) {
            return $dataStarted?'unknown':'failed';
        } finally { if (is_resource($socket)) fclose($socket); }
    }

    /** Headers only first. The caller fetches bodies solely for candidate request threads. */
    public function poll(#[\SensitiveParameter] array $credentials,?array $cursor,callable $consume): array
    {
        $settings=EmployeeMailSettingsService::validate($credentials['settings']??[]);
        $socket=$this->connect($settings['imap_host'],$settings['imap_port']);
        try {
            if (!str_starts_with($this->line($socket),'* OK')) throw new \RuntimeException('IMAP_GREETING');
            $quote=static fn(string $s):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$s).'"';
            $this->command($socket,'a1','LOGIN '.$quote($credentials['email']).' '.$quote($credentials['password']));
            $selected=$this->command($socket,'a2','EXAMINE INBOX');
            if (!preg_match('/\[UIDVALIDITY (\d+)\]/i',$selected['lines'],$v) || !preg_match('/\[UIDNEXT (\d+)\]/i',$selected['lines'],$n)) throw new \RuntimeException('IMAP_CURSOR_MISSING');
            $validity=(int)$v[1]; $next=(int)$n[1];
            // First registration starts at the mailbox frontier: no historical personal mail import.
            if ($cursor===null) return ['uidvalidity'=>$validity,'uid'=>$next-1];
            if ((int)$cursor['uidvalidity']!==$validity) throw new \RuntimeException('IMAP_UIDVALIDITY_CHANGED');
            $last=(int)$cursor['uid'];
            if ($last+1>=$next) return $cursor;
            $found=$this->command($socket,'a3','UID SEARCH UID '.($last+1).':'.($next-1));
            preg_match('/\* SEARCH([^\r\n]*)/',$found['lines'],$matches);
            $uids=array_values(array_filter(array_map('intval',preg_split('/\s+/',trim($matches[1]??''))),static fn(int $id):bool=>$id>$last && $id<$next));
            sort($uids,SORT_NUMERIC);
            foreach (array_slice($uids,0,50) as $i=>$uid) {
                $head=$this->command($socket,'h'.$i,'UID FETCH '.$uid.' (RFC822.SIZE BODY.PEEK[HEADER.FIELDS (MESSAGE-ID IN-REPLY-TO REFERENCES FROM TO CC SUBJECT DATE)])',65536);
                if (!preg_match('/RFC822.SIZE (\d+)/',$head['lines'],$size)) throw new \RuntimeException('IMAP_SIZE_MISSING');
                $headers=DocumentMailMime::headers($head['literal']);
                // consume must persist the message before returning; exception leaves cursor unchanged.
                $fetch=function() use($socket,$uid,$i,$size):array {
                    if ((int)$size[1]>DocumentMailMime::MAX_BYTES) throw new \RuntimeException('MAIL_SIZE_LIMIT');
                    $body=$this->command($socket,'b'.$i,'UID FETCH '.$uid.' (BODY.PEEK[])',DocumentMailMime::MAX_BYTES);
                    return DocumentMailMime::parse($body['literal']);
                };
                $consume($headers,$fetch,$uid,$validity);
                $last=$uid;
            }
            $this->write($socket,"zz LOGOUT\r\n");
            return ['uidvalidity'=>$validity,'uid'=>$last];
        } finally { fclose($socket); }
    }

    private function command($socket,string $tag,#[\SensitiveParameter] string $command,int $limit=65536): array
    {
        $this->write($socket,$tag.' '.$command."\r\n");
        $lines=''; $literal='';
        for ($i=0;$i<1000;$i++) {
            $line=$this->line($socket);
            $lines.=$line;
            if (strlen($lines)>65536) throw new \RuntimeException('IMAP_RESPONSE_LIMIT');
            if (preg_match('/\{(\d+)\}\r?\n$/',$line,$m)) {
                $remaining=(int)$m[1];
                if ($remaining>$limit || strlen($literal)+$remaining>$limit) throw new \RuntimeException('IMAP_LITERAL_LIMIT');
                while ($remaining>0) {
                    $chunk=fread($socket,min(8192,$remaining));
                    if ($chunk===false || $chunk==='') throw new \RuntimeException('IMAP_LITERAL_FAILED');
                    $literal.=$chunk; $remaining-=strlen($chunk);
                }
            }
            if (str_starts_with($line,$tag.' ')) {
                if (!str_starts_with($line,$tag.' OK')) throw new \RuntimeException('IMAP_COMMAND_FAILED');
                return ['lines'=>$lines,'literal'=>$literal];
            }
        }
        throw new \RuntimeException('IMAP_RESPONSE_LIMIT');
    }
}
