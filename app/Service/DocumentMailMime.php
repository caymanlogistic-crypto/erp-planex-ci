<?php
declare(strict_types=1);
namespace App\Service;

/** Bounded MIME codec. Incoming HTML is converted to text and never rendered as markup. */
final class DocumentMailMime
{
    public const MAX_BYTES = 26214400;
    public const MAX_PARTS = 60;

    public static function address(string $address): string
    {
        if (strlen($address)>254 || preg_match('/[\r\n\x00]/',$address) || !filter_var($address,FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Некорректный почтовый адрес.');
        return strtolower($address);
    }

    public static function messageId(string $id): string
    {
        if (!preg_match('/^<[a-zA-Z0-9._+@-]{3,240}>$/D',$id)) throw new \InvalidArgumentException('Некорректный идентификатор письма.');
        return $id;
    }

    public static function build(array $message): string
    {
        $from=self::address((string)$message['from']);
        $to=array_map(self::address(...),$message['to']);
        $cc=array_map(self::address(...),$message['cc']??[]);
        if (!$to) throw new \InvalidArgumentException('Укажите получателей письма.');
        $id=self::messageId((string)$message['message_id']);
        $subject=(string)$message['subject'];
        if (mb_strlen($subject)>250 || preg_match('/[\r\n\x00]/',$subject)) throw new \InvalidArgumentException('Некорректная тема письма.');
        $boundary='planex_'.bin2hex(random_bytes(18));
        $headers=['From: '.$from,'To: '.implode(', ',$to),'Message-ID: '.$id,'Date: '.gmdate('D, d M Y H:i:s').' +0000','Subject: '.self::encodeHeader($subject),'MIME-Version: 1.0','Content-Type: multipart/mixed; boundary="'.$boundary.'"'];
        if ($cc) $headers[]='Cc: '.implode(', ',array_unique($cc));
        if (!empty($message['in_reply_to'])) {
            $reply=self::messageId($message['in_reply_to']);
            $headers[]='In-Reply-To: '.$reply;
            $headers[]='References: '.$reply;
        }
        $parts=['Content-Type: text/plain; charset=UTF-8'."\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode((string)$message['text']),76,"\r\n")];
        if (count($message['attachments']??[])>self::MAX_PARTS-1) throw new \InvalidArgumentException('Слишком много вложений.');
        $size=0;
        foreach ($message['attachments']??[] as $file) {
            $name=self::filename((string)$file['name']);
            $bytes=(string)$file['bytes'];
            $size+=strlen($bytes);
            if ($size>self::MAX_BYTES*0.7) throw new \InvalidArgumentException('Общий размер вложений превышает допустимый размер письма.');
            $mime=(string)($file['mime']??'application/octet-stream');
            if (!preg_match('~^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$~D',$mime)) $mime='application/octet-stream';
            $parts[]='Content-Type: '.$mime."\r\nContent-Disposition: attachment; filename*=UTF-8''".rawurlencode($name)."\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($bytes),76,"\r\n");
        }
        $raw=implode("\r\n",$headers)."\r\n\r\n--".$boundary."\r\n".implode("\r\n--".$boundary."\r\n",$parts)."\r\n--".$boundary."--\r\n";
        if (strlen($raw)>self::MAX_BYTES) throw new \InvalidArgumentException('Письмо слишком большое.');
        return $raw;
    }

    public static function headers(string $raw): array
    {
        $block=preg_split('/\r?\n\r?\n/',$raw,2)[0];
        if (strlen($block)>65536) throw new \RuntimeException('MAIL_HEADERS_LIMIT');
        $block=preg_replace('/\r?\n[ \t]+/',' ',$block);
        $out=[];
        foreach (preg_split('/\r?\n/',$block) as $line) {
            if (!str_contains($line,':')) continue;
            [$key,$value]=explode(':',$line,2);
            $key=strtolower(trim($key));
            if (!preg_match('/^[a-z0-9-]+$/D',$key)) continue;
            $out[$key]=isset($out[$key])?$out[$key].' '.trim($value):trim($value);
        }
        return $out;
    }

    public static function parse(string $raw): array
    {
        if (strlen($raw)>self::MAX_BYTES) throw new \RuntimeException('MAIL_SIZE_LIMIT');
        $headers=self::headers($raw);
        $result=['message_id'=>$headers['message-id']??'','references'=>trim(($headers['in-reply-to']??'').' '.($headers['references']??'')),
            'from'=>self::decodeHeader($headers['from']??''),'to'=>self::decodeHeader($headers['to']??''),'cc'=>self::decodeHeader($headers['cc']??''),
            'subject'=>self::decodeHeader($headers['subject']??''),'text'=>'','attachments'=>[]];
        $count=0;
        self::part($raw,$result,$count,0);
        return $result;
    }

    private static function part(string $raw,array &$result,int &$count,int $depth): void
    {
        if (++$count>self::MAX_PARTS || $depth>8) throw new \RuntimeException('MAIL_PART_LIMIT');
        $headers=self::headers($raw);
        $body=preg_split('/\r?\n\r?\n/',$raw,2)[1]??'';
        $type=$headers['content-type']??'text/plain';
        $mime=strtolower(trim(explode(';',$type,2)[0]));
        if (str_starts_with($mime,'multipart/')) {
            $boundary=self::parameter($type,'boundary');
            if ($boundary==='' || strlen($boundary)>200) throw new \RuntimeException('MAIL_BOUNDARY_INVALID');
            if (!preg_match('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'--[ \t]*(?:\r?\n|\z)/',$body)) throw new \RuntimeException('MAIL_BOUNDARY_INCOMPLETE');
            $chunks=preg_split('/(?:\A|\r?\n)--'.preg_quote($boundary,'/').'(--)?[ \t]*(?:\r?\n|\z)/',$body);
            // Preamble and epilogue are not message parts.
            foreach (array_slice($chunks,1,-1) as $chunk) self::part($chunk,$result,$count,$depth+1);
            return;
        }
        $encoding=strtolower($headers['content-transfer-encoding']??'');
        if ($encoding==='base64') {
            $body=base64_decode(preg_replace('/\s+/','',$body),true);
            if ($body===false) throw new \RuntimeException('MAIL_BASE64_INVALID');
        } elseif ($encoding==='quoted-printable') $body=quoted_printable_decode($body);
        $disposition=$headers['content-disposition']??'';
        $name=self::parameter($disposition,'filename')?:self::parameter($type,'name');
        if ($name!=='' || str_starts_with(strtolower($disposition),'attachment') || !in_array($mime,['text/plain','text/html'],true)) {
            $result['attachments'][]=['name'=>self::filename($name?:'Вложение.bin'),'mime'=>$mime,'bytes'=>$body,'sha256'=>hash('sha256',$body)];
            return;
        }
        $charset=self::parameter($type,'charset')?:'UTF-8';
        if (strcasecmp($charset,'UTF-8')!==0) {
            $converted=@iconv($charset,'UTF-8//IGNORE',$body);
            if ($converted!==false) $body=$converted;
        }
        $body=mb_convert_encoding($body,'UTF-8','UTF-8');
        if ($mime==='text/html') {
            $body=preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is','',$body);
            $body=html_entity_decode(strip_tags(preg_replace('~<(?:br|/p|/div)\b[^>]*>~i',"\n",$body)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        }
        if ($mime==='text/plain' || $result['text']==='') $result['text'].=mb_substr($body,0,100000)."\n";
    }

    public static function filename(string $name): string
    {
        $name=self::decodeHeader($name);
        $name=preg_replace('~[\\\\/\x00-\x1f\x7f]~u','_', $name);
        $name=trim((string)$name," .\t\n\r\0\x0B");
        if ($name==='') return 'Вложение.bin';
        $extension=pathinfo($name,PATHINFO_EXTENSION);
        if (mb_strlen($name)>180 && preg_match('/^[a-zA-Z0-9]{1,12}$/D',$extension)) return mb_substr($name,0,179-strlen($extension)).'.'.$extension;
        return mb_substr($name,0,180);
    }

    private static function parameter(string $value,string $name): string
    {
        if (preg_match_all('/;\s*'.preg_quote($name,'/').'\*(\d+)(\*)?=(?:"([^"]*)"|([^;]*))/i',$value,$chunks,PREG_SET_ORDER)) {
            $pieces=[];$encoded=false;
            foreach ($chunks as $chunk) {$pieces[(int)$chunk[1]]=$chunk[3]!==''?$chunk[3]:trim($chunk[4]??'');$encoded=$encoded||($chunk[2]??'')==='*';}
            ksort($pieces);
            if (array_keys($pieces)!==range(0,count($pieces)-1)) throw new \RuntimeException('MAIL_FILENAME_INVALID');
            $joined=implode('',$pieces);
            if ($encoded) {$parts=explode("'",$joined,3);return rawurldecode(count($parts)===3?$parts[2]:$joined);}
            return $joined;
        }
        if (preg_match('/;\s*'.preg_quote($name,'/').'\*=([^;]+)/i',$value,$m)) {
            $parts=explode("'",trim($m[1]," \t\""),3);
            return rawurldecode(count($parts)===3?$parts[2]:$parts[0]);
        }
        if (preg_match('/;\s*'.preg_quote($name,'/').'=(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/i',$value,$m)) return stripcslashes($m[1]!==''?$m[1]:trim($m[2]??''));
        return '';
    }

    public static function decodeHeader(string $value): string
    {
        if (str_contains($value,'=?')) $value=iconv_mime_decode($value,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8')?:$value;
        return mb_convert_encoding($value,'UTF-8','UTF-8');
    }

    private static function encodeHeader(string $value): string
    {
        return implode("\r\n ",array_map(static fn(string $part):string=>'=?UTF-8?B?'.base64_encode($part).'?=',mb_str_split($value,15)));
    }
}
