<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$user=require_login();if($user['role']==='system_admin'){header('Location: admin.php');exit;}
$id=(int)($_GET['id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$s=$pdo->prepare("DELETE FROM content WHERE id=? AND owner_id=? AND status<>'published'");$s->execute([$id,$user['id']]);header('Location: workspace.php');exit;}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="assets/css/site.css"><title>Delete draft</title></head><body><main class="wrap" style="max-width:600px;padding:60px 0"><div class="panel"><h1>Delete this draft?</h1><p>Only your own unpublished content can be deleted here.</p><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button class="btn">Confirm delete</button> <a class="btn light" href="workspace.php">Cancel</a></form></div></main></body></html>
