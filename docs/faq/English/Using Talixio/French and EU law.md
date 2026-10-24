# French and EU law

The assistant can search French and European law directly, on official public sources. Nothing is sent to a third
party: it calls the government and EU APIs itself.

## French law — Légifrance and JudiLibre
Ask things like "que dit l'article 1240 du Code civil ?", "trouve la loi informatique et libertés", or "jurisprudence
de la Cour de cassation sur la responsabilité du fait des choses". It searches **Légifrance** (codes, laws, decrees,
the Journal officiel, case law) and **JudiLibre** (decisions of the Cour de cassation and the courts of appeal), and
can read a text or a decision in full.

This needs a free account on **piste.gouv.fr**:
1. Create an account, create an application, and subscribe it to the **Légifrance** and **JudiLibre** APIs.
2. Put the application's client ID and secret in Settings → Connected accounts → French & EU law, save, and press
   **Test connection**.

## EU law — EUR-Lex
Ask "lis le RGPD", "le règlement IA (AI Act)", or search by title. It uses **EUR-Lex / the Publications Office** and
needs **no key**. Read any document by its CELEX number (e.g. 32016R0679 for the GDPR, 32024R1689 for the AI Act).
EU content is reused under Commission Decision 2011/833/EU.

This is general information, not legal advice.
