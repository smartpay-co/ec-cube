<?php

namespace Plugin\Smartpay\Entity;

use Doctrine\ORM\Mapping as ORM;

if (!class_exists('\Plugin\Smartpay\Entity\Config', false)) {
    /**
     * Config
     *
     * @ORM\Table(name="plg_smartpay_config")
     * @ORM\Entity(repositoryClass="Plugin\Smartpay\Repository\ConfigRepository")
     */
    class Config
    {
        /**
         * @var int
         *
         * @ORM\Column(name="id", type="integer", options={"unsigned":true})
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="IDENTITY")
         */
        private $id;

        /**
         * @var string
         *
         * @ORM\Column(name="api_prefix", type="string", length=255)
         */
        private $api_prefix;

        /**
         * @return int
         */
        public function getId()
        {
            return $this->id;
        }

        /**
         * @return string
         */
        public function getAPIPrefix()
        {
            return $this->api_prefix;
        }

        /**
         * @param string $api_id
         *
         * @return $this;
         */
        public function setApiId($api_id)
        {
            $this->api_id = $api_id;

            return $this;
        }

        /**
         * @param string $api_prefix
         *
         * @return $this;
         */
        public function setAPIPrefix($api_prefix)
        {
            $this->api_prefix = $api_prefix;

            return $this;
        }
    }
}
