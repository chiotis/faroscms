# A cache in front of the site (Varnish)

Public pages that a visitor sees without a session say `Cache-Control: public, max-age=0, s-maxage=60`, so a cache in front of the
site may keep them. Whatever is saved in the admin (content, settings, menus, media, redirects, an update) empties that cache, so
the change shows at once, however long the cache keeps a page. **System > Page cache** shows what the site found and has a button
that empties the cache by hand.

Not kept: what an editor sees (they have a session and may see drafts), the maintenance page during an update, the analytics
beacon and a picture that could not be made.

## CloudPanel

Nothing to set up. With Varnish Cache on for the site, CloudPanel runs its controller (`~/.varnish-cache/controller.php`) before
the CMS; the CMS finds it and hands it a purge of the site's cache tag (the Cache Tag Prefix in CloudPanel), which it sends when
the request ends. CloudPanel's own Cache Lifetime decides how long a page is kept (its controller replaces the CMS's
`Cache-Control`); with the purge on every change it can stay long.

## Any other Varnish

Write its address (`127.0.0.1:6081`) under System > Page cache. On every change the CMS sends `PURGE /` with the site's host;
the VCL turns that into a ban of everything of that host:

```vcl
acl purgers { "127.0.0.1"; "::1"; }

sub vcl_recv {
    if (req.method == "PURGE") {
        if (client.ip !~ purgers) { return (synth(405, "Not allowed")); }
        ban("req.http.host == " + req.http.host);
        return (synth(200, "Purged"));
    }
}
```
