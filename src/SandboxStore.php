<?php
declare(strict_types=1);
namespace Demo;

use PDO;
use Throwable;

final class SandboxStore
{
    private PDO $db;
    private string $token;
    public readonly string $csrf;

    public function __construct(string $directory)
    {
        if(!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Storage unavailable');
        $file=$directory.'/sandbox.sqlite';
        $this->db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        chmod($file,0600);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS sandboxes (token TEXT PRIMARY KEY, csrf TEXT NOT NULL, state TEXT NOT NULL, updated INTEGER NOT NULL)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS rates (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL)');
        $raw=$_COOKIE['ecommerce_demo']??'';
        if(!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/D',$raw)) $raw=bin2hex(random_bytes(32));
        $this->token=hash('sha256',$raw);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $now=time();
            $this->db->prepare('DELETE FROM sandboxes WHERE updated < ?')->execute([$now-3600]);
            $this->db->prepare('DELETE FROM rates WHERE expires < ?')->execute([$now]);
            $this->rate('ip:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'local'),300,$now);
            $this->rate('sandbox:'.$this->token,120,$now);
            $q=$this->db->prepare('SELECT csrf FROM sandboxes WHERE token=?');$q->execute([$this->token]);
            $csrf=$q->fetchColumn();
            if($csrf===false) {
                if((int)$this->db->query('SELECT COUNT(*) FROM sandboxes')->fetchColumn()>=1000) throw new ApiError(429,'sandbox_capacity','The demo is busy. Please try again later.');
                $csrf=bin2hex(random_bytes(24));
                $this->db->prepare('INSERT INTO sandboxes(token,csrf,state,updated) VALUES(?,?,?,?)')->execute([$this->token,$csrf,json_encode(Catalog::initialState(),JSON_THROW_ON_ERROR),$now]);
            }
            $this->csrf=(string)$csrf;
            $this->db->exec('COMMIT');
        } catch(Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
        $host=parse_url('http://'.($_SERVER['HTTP_HOST']??'localhost'),PHP_URL_HOST);
        $secure=$host==='nikitazalesskiy.ru'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
        setcookie('ecommerce_demo',$raw,['expires'=>time()+3600,'path'=>'/api/demo/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    }

    private function rate(string $prefix,int $limit,int $now): void
    {
        $key=$prefix.':'.intdiv($now,60);
        $q=$this->db->prepare('INSERT INTO rates(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1');$q->execute([$key,$now+120]);
        $q=$this->db->prepare('SELECT count FROM rates WHERE key=?');$q->execute([$key]);
        if((int)$q->fetchColumn()>$limit) throw new ApiError(429,'rate_limited','Too many requests. Please try again in a minute.');
    }

    public function transaction(callable $callback): mixed
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare('SELECT state FROM sandboxes WHERE token=?');$q->execute([$this->token]);
            $state=json_decode((string)$q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
            $result=$callback($state);
            $this->db->prepare('UPDATE sandboxes SET state=?,updated=? WHERE token=?')->execute([json_encode($state,JSON_THROW_ON_ERROR),time(),$this->token]);
            $this->db->exec('COMMIT');return $result;
        } catch(Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
}
