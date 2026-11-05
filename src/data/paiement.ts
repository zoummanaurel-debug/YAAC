/**
 * Moyens de paiement mobile affichés sur /devenir-membre/.
 *
 * Ces valeurs sont publiques (elles s'affichent sur la page) : elles vivent
 * donc ici, dans le dépôt, et non dans config.php. Les modifier demande un
 * nouveau déploiement, c'est-à-dire un commit sur `main`.
 *
 * Chariow (carte bancaire, autres pays) ne se règle pas ici : son lien est
 * dans config.php, côté serveur (`liens.checkout`).
 */

export const paiement = {
  /** Montant de l'adhésion, en FCFA. Doit rester égal au prix Chariow. */
  montant: 6000,

  wave: {
    /**
     * Lien marchand permanent du compte Wave Business de YAAC, SANS montant.
     * Il se copie depuis l'application Wave Business (QR code marchand →
     * partager) et ressemble à :
     *   https://pay.wave.com/m/M_sn_XXXXXXXXXX/c/sn/
     *
     * Vide : l'option Wave n'apparaît pas sur le site.
     */
    lien: '',
  },

  orangeMoney: {
    /** Numéro qui reçoit les transferts, tel qu'il s'affiche. */
    numero: '+221 71 032 22 44',
    /** Le même, en chiffres seuls, pour le bouton « copier ». */
    numeroBrut: '710322244',
    /** Code USSD d'Orange Money Sénégal. */
    ussd: '#144#',
  },
} as const;

/** Lien Wave avec le montant pré-rempli, ou chaîne vide si non configuré. */
export function lienWave(): string {
  const brut = paiement.wave.lien.trim();
  if (!brut) return '';
  const url = new URL(brut);
  url.searchParams.set('amount', String(paiement.montant));
  return url.toString();
}
