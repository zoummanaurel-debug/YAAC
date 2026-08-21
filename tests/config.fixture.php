<?php
/**
 * Configuration FACTICE pour les tests. Aucune valeur réelle ici : le dépôt
 * est public. Le lien WhatsApp et les secrets sont des exemples.
 */
return [
  'db' => ['host'=>'localhost','name'=>'test','user'=>'test','password'=>'test'],
  'chariow' => ['pulse_secret'=>'whsec_secret_de_test','api_key'=>'sk_live_test','product_id'=>'prd_2tljqv9n'],
  'adhesion' => ['montant_affiche'=>'6 000 FCFA','validite_annees'=>5,'role_defaut'=>'Membre'],
  'liens' => [
    'site'=>'https://yaac.network',
    'badge'=>'https://yaac.network/badge/',
    'verify'=>'https://yaac.network/verify/',
    'whatsapp'=>'https://chat.whatsapp.com/EXEMPLE-DE-LIEN',
  ],
  'mail' => [
    'transport'=>'smtp','from_email'=>'communication@yaac.network',
    'from_nom'=>'YAAC — Youth Alliance for Agroecology and Climate',
    'reply_to'=>'communication@yaac.network','bcc'=>'',
    'smtp'=>['host'=>'smtp.hostinger.com','port'=>465,'chiffrement'=>'ssl','user'=>'communication@yaac.network','password'=>'x','timeout'=>20],
  ],
  'verification_secret'=>'secret_de_test_pour_les_jetons',
  'cors_origines' => ['https://yaac.network'],
  'log' => 'php://temp',
];
