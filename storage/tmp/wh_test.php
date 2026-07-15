<?php
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
DB::beginTransaction();
try {
  $logFile = storage_path('logs/laravel.log');
  $mark = file_exists($logFile) ? filesize($logFile) : 0;
  Http::fake(); // don't let the flow engine actually call Meta
  $c = app(App\Http\Controllers\Api\WhatsAppWebhookController::class);

  // 1. bad signature
  Setting::put('whatsapp.app_secret', 'topsecret', true);
  $r = Illuminate\Http\Request::create('/wh','POST',[],[],[],['HTTP_X_HUB_SIGNATURE_256'=>'sha256=wrong'],'{"a":1}');
  echo "A) bad signature      -> HTTP ".$c->handle($r)->getStatusCode()."\n";

  // 2. valid signature, but a STATUS callback (no message)
  $body = json_encode(['entry'=>[['changes'=>[['field'=>'messages','value'=>['statuses'=>[['status'=>'delivered']]]]]]]]);
  $sig = 'sha256='.hash_hmac('sha256', $body, 'topsecret');
  $r = Illuminate\Http\Request::create('/wh','POST',[],[],[],['HTTP_X_HUB_SIGNATURE_256'=>$sig,'CONTENT_TYPE'=>'application/json'],$body);
  echo "B) status callback    -> ".json_encode($c->handle($r)->getData())."\n";

  // 3. valid signature, a real text reply
  $body = json_encode(['entry'=>[['changes'=>[['field'=>'messages','value'=>['messages'=>[
      ['from'=>'60123456789','type'=>'text','text'=>['body'=>'Hi there']]]]]]]]]);
  $sig = 'sha256='.hash_hmac('sha256', $body, 'topsecret');
  $r = Illuminate\Http\Request::create('/wh','POST',[],[],[],['HTTP_X_HUB_SIGNATURE_256'=>$sig,'CONTENT_TYPE'=>'application/json'],$body);
  echo "C) real text reply    -> ".json_encode($c->handle($r)->getData())."\n";
  echo "   logged inbound row? ".(App\Models\WhatsappMessage::where('direction','in')->where('phone','60123456789')->exists() ? 'YES' : 'NO')."\n";

  // 4. media-only reply (the known gap)
  $body = json_encode(['entry'=>[['changes'=>[['field'=>'messages','value'=>['messages'=>[
      ['from'=>'60123456789','type'=>'image','image'=>['id'=>'MID']]]]]]]]]);
  $sig = 'sha256='.hash_hmac('sha256', $body, 'topsecret');
  $r = Illuminate\Http\Request::create('/wh','POST',[],[],[],['HTTP_X_HUB_SIGNATURE_256'=>$sig,'CONTENT_TYPE'=>'application/json'],$body);
  echo "D) image-only reply   -> ".json_encode($c->handle($r)->getData())."\n";

  echo "--- new log lines ---\n";
  $new = substr(file_get_contents($logFile), $mark);
  foreach (explode("\n", $new) as $l) if (str_contains($l,'[WhatsApp]')) echo "  ".trim(substr($l, strpos($l,'[WhatsApp]'), 150))."\n";
} catch (\Throwable $e) { echo "ERROR: ".$e->getMessage()." @".$e->getLine()."\n"; }
DB::rollBack();
