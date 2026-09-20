<?php
declare(strict_types=1);

namespace App\Service;

require_once __DIR__.'/EmployeeMailSettingsService.php';

/** Authentication only, using saved SSL settings and public network endpoints. */
class EmployeeMailTransport
{
    public function check(#[\SensitiveParameter] array $credentials): array
    {
        $settings=EmployeeMailSettingsService::validate($credentials['settings']??[]);
        $result=['imap'=>false,'smtp'=>false];
        try {
            $socket=$this->connect($settings['imap_host'],$settings['imap_port']);
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

    protected function connect(string $host,int $port)
    {
        $addresses=gethostbynamel($host);
        if (!$addresses) throw new \RuntimeException('MAIL_DNS_FAILED');
        foreach ($addresses as $address) {
            if (!filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('MAIL_HOST_NOT_PUBLIC');
        }
        // Pin the validated address; TLS still validates the original server name.
        $address=$addresses[0];
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true,'crypto_method'=>STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT]]);
        $socket=@stream_socket_client('ssl://'.$address.':'.$port,$errno,$error,15,STREAM_CLIENT_CONNECT,$context);
        if (!$socket) throw new \RuntimeException('MAIL_CONNECT_FAILED');
        stream_set_timeout($socket,15);
        return $socket;
    }

    protected function smtpLogin(#[\SensitiveParameter] array $credentials)
    {
        $socket=$this->connect($credentials['settings']['smtp_host'],(int)$credentials['settings']['smtp_port']);
        try {
            $this->expect($socket,220);
            $this->write($socket,"EHLO planex.local\r\n"); $this->expect($socket,250);
            $this->write($socket,"AUTH LOGIN\r\n"); $this->expect($socket,334);
            $this->write($socket,base64_encode($credentials['email'])."\r\n"); $this->expect($socket,334);
            $this->write($socket,base64_encode($credentials['password'])."\r\n"); $this->expect($socket,235);
            return $socket;
        } catch (\Throwable $e) { fclose($socket); throw $e; }
    }

    protected function expect($socket,int $code): void
    {
        for ($i=0;$i<100;$i++) {
            $line=$this->line($socket);
            if ((int)substr($line,0,3)!==$code) throw new \RuntimeException('MAIL_PROTOCOL_FAILED');
            if (($line[3]??'')===' ') return;
        }
        throw new \RuntimeException('MAIL_RESPONSE_LIMIT');
    }

    protected function line($socket): string
    {
        $line=fgets($socket,8192);
        if ($line===false || !str_ends_with($line,"\n")) throw new \RuntimeException('MAIL_READ_FAILED');
        return $line;
    }

    protected function write($socket,#[\SensitiveParameter] string $data): void
    {
        while ($data!=='') {
            $n=fwrite($socket,$data);
            if (!$n) throw new \RuntimeException('MAIL_WRITE_FAILED');
            $data=substr($data,$n);
        }
    }
}
