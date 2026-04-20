<?php

declare(strict_types=1);

namespace yii2\extensions\sentry\tests\models;

use yii\base\BaseObject;
use yii\web\IdentityInterface;

class User extends BaseObject implements IdentityInterface
{
    /**
     * @var int
     */
    public int $id = 1;

    /**
     * @var string
     */
    public string $username = 'JohnDoe';

    /**
     * @var string
     */
    public string $email = 'john.doe@example.com';

    /**
     * {@inheritdoc}
     */
    public static function findIdentity($id)
    {
        return new self(['id' => $id]);
    }

    /**
     * {@inheritdoc}
     */
    public static function findIdentityByAccessToken($token, $type = null)
    {
        return new self();
    }

    /**
     * {@inheritdoc}
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthKey(): ?string
    {
        return '123';
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthKey($authKey): ?bool
    {
        return true;
    }
}
