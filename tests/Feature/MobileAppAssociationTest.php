<?php

test('lets the ios app open portal links and use its passkeys', function () {
    config(['mobile.ios.app_ids' => ['ABCDE12345.com.texasrenters.portal']]);

    $this->get('/.well-known/apple-app-site-association')
        ->assertOk()
        ->assertJsonPath('applinks.details.0.appIDs', ['ABCDE12345.com.texasrenters.portal'])
        ->assertJsonPath('applinks.details.0.components', [
            ['/' => '/invitations/*'],
            ['/' => '/reset-password/*'],
            ['/' => '/email/confirm/*'],
        ])
        ->assertJsonPath('webcredentials.apps', ['ABCDE12345.com.texasrenters.portal']);
});

test('lets the android app open portal links and use its passkeys', function () {
    config([
        'mobile.android.package' => 'com.texasrenters.portal',
        'mobile.android.sha256_cert_fingerprints' => ['AB:CD'],
    ]);

    $this->get('/.well-known/assetlinks.json')
        ->assertOk()
        ->assertJsonPath('0.target.package_name', 'com.texasrenters.portal')
        ->assertJsonPath('0.target.sha256_cert_fingerprints', ['AB:CD'])
        ->assertJsonPath('0.relation', ['delegate_permission/common.handle_all_urls', 'delegate_permission/common.get_login_creds']);
});

test('publishes no association until an app is configured', function () {
    config(['mobile.ios.app_ids' => [], 'mobile.android.package' => null]);

    $this->get('/.well-known/apple-app-site-association')->assertNotFound();
    $this->get('/.well-known/assetlinks.json')->assertNotFound();
});
