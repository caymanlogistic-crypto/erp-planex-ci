<?php
declare(strict_types=1);

namespace App\Service;

/** Fixed Spaceweb endpoints prevent settings from becoming an SSRF proxy. */
final class EmployeeMailTransport
{
    public const IMAP_HOST='imap.spaceweb.ru';
    public const SMTP_HOST='smtp.spaceweb.ru';

    public function check(#[\SensitiveParameter] array $credentials): array
    {
        $result=['imap'=>false,'smtp'=>false];
        try {
            $socket=$this->connect(self::IMAP_HOST,993);
            try {
                $greeting=$this->line($socket);
                if (!str_starts_with($greeting,'* OK')) throw new \RuntimeException('IMAP_GREETING');
                $quote=static fn(string $s): string => '"'.str_replace(['\\','"'],['\\\\','\\"'],$s).'"';
                $this->write($socket,'a1 LOGIN '.$quote($credentials['email']).' '.$quote($credentials['password'])."\r\n");
                for ($i=0;$i<100;$i++) {
                    $line=$this->line($socket);
                    if (str_starts_with($line,'a1 ')) {
                        $result['imap']=str_starts_with($line,'a1 OK');
                        break;
                    }
                }
                $this->write($socket,"a2 LOGOUT\r\n");
            } finally { fclose($socket); }
        } catch (\Throwable) { /* Provider replies and credentials never enter logs. */ }
        try {
            $socket=$this->smtpLogin($credentials);
            try { $result['smtp']=true; $this->write($socket,"QUIT\r\n"); }
            finally { fclose($socket); }
        } catch (\Throwable) { }
        return $result;
    }

    private function connect(string $host,int $port)
    {
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $socket=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$context);
        if (!$socket) throw new \RuntimeException('MAIL_CONNECT_FAILED');
        stream_set_timeout($socket,15);
        return $socket;
    }

    private function smtpLogin(#[\SensitiveParameter] array $credentials)
    {
        $socket=$this->connect(self::SMTP_HOST,465);
        try {
            $this->expect($socket,220);
            $this->write($socket,"EHLO planex.local\r\n"); $this->expect($socket,250);
            $this->write($socket,"AUTH LOGIN\r\n"); $this->expect($socket,334);
            $this->write($socket,base64_encode($credentials['email'])."\r\n"); $this->expect($socket,334);
            $this->write($socket,base64_encode($credentials['password'])."\r\n"); $this->expect($socket,235);
            return $socket;
        } catch (\Throwable $e) { fclose($socket); throw $e; }
    }

    private function expect($socket,int $code): void
    {
        for ($i=0;$i<100;$i++) {
            $line=$this->line($socket);
            if ((int)substr($line,0,3)!==$code) throw new \RuntimeException('MAIL_PROTOCOL_FAILED');
            if (($line[3]??'')===' ') return;
        }
        throw new \RuntimeException('MAIL_RESPONSE_LIMIT');
    }

    private function line($socket): string
    {
        $line=fgets($socket,8192);
        if ($line===false || !str_ends_with($line,"\n")) throw new \RuntimeException('MAIL_READ_FAILED');
        return $line;
    }

    private function write($socket,#[\SensitiveParameter] string $data): void
    {
        while ($data!=='') {
            $n=fwrite($socket,$data);
            if (!$n) throw new \RuntimeException('MAIL_WRITE_FAILED');
            $data=substr($data,$n);
        }
    }
}
