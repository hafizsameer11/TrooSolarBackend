<?php

namespace App\Support;

class BnplFinanceAgreement
{
    public static function residentialText(): string
    {
        return <<<'TXT'
AUTHORITY TO DEBIT MY ACCOUNT:

In accordance with the Terms and Conditions of the sale agreement, I authorize Troosolar's approved financing, payment or banking partner to debit my nominated bank account with payments required to pay for the purchase of the item(s) above over the agreed repayment schedule. I consent to irrevocably undertake and covenant that I shall at all times make funds available in my said account for the purpose of meeting up with my obligations as and when due. The foregoing shall be construed as continuing instructions and shall not be revoked by me until I have fully paid down the credit availed to me.
TXT;
    }

    public static function smeText(): string
    {
        return <<<'TXT'
PROMISE TO PURCHASE/LEASE:

I hereby covenant with Troosolar's approved financing, payment or banking partner to purchase the renewable energy product(s) on deferred payment terms and complete the sale/lease under the contract which shall be executed as per the agreement upon delivery of these terms. In accordance with the Terms and Conditions of the agreement, I authorise Troosolar's approved financing, payment or banking partner to debit my nominated bank account with payments required to pay for the purchase of the item(s) above over the agreed repayment schedule. I hereby irrevocably undertake and covenant that I shall at all times make funds available in my said account for the purpose of meeting up with my obligations as and when due. The foregoing shall be construed as a continuing instruction and shall not be revoked by me until I have fully paid down the credit availed to me.

DECLARATION:

You make the following declaration to us:
The deferred payment contract is governed by this application form and the terms and conditions attached hereto. The acceptance of your application for deferred payment sales shall be at the discretion of Troosolar's approved financing, payment, or banking partner, and we shall not be obliged to furnish reasons to you should your application not be accepted. If we accept your application, we will let you know in writing.

I/we am/are at least 21 years of age.
I/we confirm that all the details given in this application are true and complete and I/we understand that these will be used to form the basis of any financing offered.
I/we authorize you to conduct any enquiry you consider necessary and appropriate for the purpose of evaluating this application from my/our employer, if any and from any other source to which you may apply including a credit search with one or more credit reference agencies, and confirm that I/we am/are not currently under administration, sequestration, debt review, or a restructuring order.
I/we accept that any facility offered to me/us is complete, and Troosolar's approved financing, payment or banking partner may withdraw, revise or cancel such offer at anytime before, during or after acceptance of the offer.
I/we undertake to notify Troosolar immediately in writing of any situation which materially changes the representation of this application, and I/we understand that Troosolar's approved financing, payment or banking partner may amend or withdraw any offer previously made.
I/we understand that Troosolar's approved financing, payment or banking partner will disclose my/our details to any insurers, auditors, professional advisers or any persons providing services to Troosolar's approved financing, payment or banking partner's relevant regulatory body as envisaged by this application form or with my/our written consent. I/we acknowledge that Troosolar's approved financing, payment or banking partner may at anytime transfer Troosolar's approved financing, payment or banking partner stake in the facility, together with any security I/we give, to any other lender bank or institution without first seeking my/our permission and I/we authorize Troosolar to disclose any information which Troosolar holds/possesses about me/us to such entity. I/we hereby authorize Troosolar to disclose any information in respect of my/our account to the guarantors for as long as the guarantor's liability for the debt outstanding.
I/we agree that by taking up all or part of any facility offered by Troosolar's approved financing, payment or banking partner based on the information provided on this application form and by signing this form, I/we agree to accept all the terms & conditions set out.
I/we agree that if I/we receive more than one letter, the letter showing the latest date will be that which applies.
I/we understand that the Bank may set off any amounts due under the agreement against any sums owing by me/us to Troosolar's approved financing, payment or banking partner (whether jointly or severally) and otherwise combine and consolidate all or any of my/our accounts with Troosolar's approved financing, payment or banking partner whether current, deposit, loan or any other nature and whether accounts in my/our name or jointly with others and whether in any other currency. Any currency conversions required to be effected by Troosolar's approved financing, payment or banking partner pursuant to this right shall be effected in accordance with the usual practice of Troosolar's approved financing, payment or banking partner.
I/we have personally completed this application form, or if completed by someone else, have read and checked every answer and I/we fully understand the implications of the wording and terms so contained. I/we hereby confirm my/our application for the above item(s) or product(s) and certify that all information provided by me/us above and attached thereto is correct and complete. The facility shall not be utilized for any act of terrorism or other illegal or prohibited acts.
I/we authorize you to make any enquiry you consider necessary and appropriate for the purpose of evaluating this application.
TXT;
    }

    public static function forCustomerType(?string $customerType): string
    {
        $type = strtolower(trim((string) $customerType));

        return in_array($type, ['sme', 'commercial'], true)
            ? self::smeText()
            : self::residentialText();
    }
}
