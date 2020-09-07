<?php
/*
    Flexisip Account Manager is a set of tools to manage SIP accounts.
    Copyright (C) 2019 Belledonne Communications SARL, All rights reserved.

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as
    published by the Free Software Foundation, either version 3 of the
    License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace Tests\Feature;

use App\Password;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AuthenticateDigestTest extends TestCase
{
    use RefreshDatabase;

    protected $route = '/api/ping';
    protected $method = 'GET';

    public function testMandatoryFrom()
    {
        $password = factory(Password::class)->create();
        $response = $this->json($this->method, $this->route);
        $response->assertStatus(422);
    }

    public function testWrongFrom()
    {
        $password = factory(Password::class)->create();
        $response = $this->withHeaders([
            'From' => 'sip:missing@username',
        ])->json($this->method, $this->route);

        $response->assertStatus(404);
    }

    public function testAuthenticate()
    {
        $password = factory(Password::class)->create();
        $response = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
        ])->json($this->method, $this->route);
        $response->assertStatus(401);
    }

    public function testMultiHash()
    {
        // Two password and we link the second to the first related account
        $passwordMD5 = factory(Password::class)->create();
        $passwordSHA256 = factory(Password::class)->states('sha256')->make();
        $passwordSHA256->account_id = $passwordMD5->account_id;
        $passwordSHA256->save();

        $response = $this->withHeaders([
            'From' => 'sip:'.$passwordMD5->account->identifier,
        ])->json($this->method, $this->route);

        $response->assertStatus(401);

        $this->assertStringContainsString('algorithm=MD5', $response->headers->all()['www-authenticate'][0]);
        $this->assertStringContainsString('algorithm=SHA-256', $response->headers->all()['www-authenticate'][1]);
    }

    public function testReplayNonce()
    {
        $password = factory(Password::class)->create();
        $response0 = $this->generateFirstResponse($password);
        $response1 = $this->generateSecondResponse($password, $response0)
            ->json($this->method, $this->route);

        $response1->assertStatus(200);

        // We increment the nc
        $response2 = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response1, 'md5', '00000002'),
        ])->json($this->method, $this->route);

        $response2->assertStatus(200);

        // We don't increment it
        $response3 = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response2, 'md5', '00000002'),
        ])->json($this->method, $this->route);

        $response3->assertSee('Nonce replayed');
        $response3->assertStatus(401);
    }

    public function testClearedNonce()
    {
        $password = factory(Password::class)->create();
        $response1 = $this->generateFirstResponse($password);
        $response2 = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response1, 'md5', '00000001'),
        ])->json($this->method, $this->route);

        $response2->assertStatus(200);

        // We remove the account related nonce
        $password->account->nonces()->first()->delete();

        $response3 = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response2, 'md5', '00000002'),
        ])->json($this->method, $this->route);

        $response3->assertSee('Nonce invalid');
        $response3->assertStatus(401);
        $this->assertStringContainsString('algorithm=MD5', $response3->headers->all()['www-authenticate'][0]);
    }

    public function testAuthenticationMD5()
    {
        $password = factory(Password::class)->create();
        $response = $this->generateFirstResponse($password);
        $response = $this->generateSecondResponse($password, $response)
                         ->json($this->method, $this->route);

        $this->assertStringContainsString('algorithm=MD5', $response->headers->all()['www-authenticate'][0]);

        $response->assertStatus(200);
    }

    public function testAuthenticationSHA265()
    {
        $password = factory(Password::class)->states('sha256')->create();
        $response = $this->generateFirstResponse($password);
        $response = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response, 'sha256'),
        ])->json($this->method, $this->route);

        $this->assertStringContainsString('algorithm=SHA-256', $response->headers->all()['www-authenticate'][0]);

        $response->assertStatus(200);
    }

    public function testAuthenticationSHA265FromCLRTXT()
    {
        $password = factory(Password::class)->states('clrtxt')->create();
        $response = $this->generateFirstResponse($password);;

        // The server is generating all the available hash algorythms
        $this->assertStringContainsString('algorithm=MD5', $response->headers->all()['www-authenticate'][0]);
        $this->assertStringContainsString('algorithm=SHA-256', $response->headers->all()['www-authenticate'][1]);

        // Let's simulate a local hash for the clear password
        $hash = 'sha256';
        $password->password = hash(
            $hash,
            $password->account->username.':'.$password->account->domain.':'.$password->password
        );

        $response = $this->withHeaders([
            'From' => 'sip:'.$password->account->identifier,
            'Authorization' => $this->generateDigest($password, $response, $hash),
        ])->json($this->method, $this->route);

        $this->assertStringContainsString('algorithm=MD5', $response->headers->all()['www-authenticate'][0]);
        $this->assertStringContainsString('algorithm=SHA-256', $response->headers->all()['www-authenticate'][1]);

        $response->assertStatus(200);
    }

    public function testAuthenticationBadPassword()
    {
        $password = factory(Password::class)->create();
        $response = $this->generateFirstResponse($password);;
        $password->password = 'wrong';

        $response = $this->generateSecondResponse($password, $response)
                         ->json($this->method, $this->route);

        $response->assertStatus(401);
    }
}
