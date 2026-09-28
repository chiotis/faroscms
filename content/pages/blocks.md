---
title: Βιβλιοθήκη blocks
status: published
visible: false
translation_id: 7b1c2d3e4f5a6b7c
excerpt: 'Όλα τα blocks του θέματος και οι παραλλαγές τους σε μία σελίδα. Ορατή μόνο σε συνδεδεμένους διαχειριστές.'
seo:
  noindex: true
blocks:
  - type: hero
    variant: minimal
    eyebrow: Βιβλιοθήκη blocks
    heading: Όλες οι ενότητες του θέματος σε μία σελίδα.
    text: Χρησιμοποιήστε τη σελίδα αυτή για να δείτε κάθε block, κάθε παραλλαγή και κάθε φόντο πριν τα βάλετε σε πραγματικές σελίδες.
    actions:
      - label: Ενότητες κειμένου
        url: '#text'
      - label: Φόρμα
        url: '#form'
        style: secondary
  - type: text
    variant: lead
    anchor: text
    eyebrow: 'Text · lead'
    heading: Εισαγωγή με μεγάλα γράμματα
    body: Ιδανικό για την πρώτη παράγραφο μιας σελίδας, όπου θέλετε να πείτε **το βασικό μήνυμα** καθαρά και με άνεση.
  - type: text
    variant: split
    tone: muted
    eyebrow: 'Text · split'
    heading: Τίτλος δίπλα στο κείμενο
    body: |
      Η παραλλαγή αυτή κρατά τον τίτλο σταθερό αριστερά όσο διαβάζετε το κείμενο δεξιά, κάτι που βοηθά σε μεγάλα κείμενα.

      ### Υπότιτλος

      Υποστηρίζει κανονικά Markdown: **έντονα**, *πλάγια*, [συνδέσμους](/about), λίστες και παραθέματα.

      > Ένα παράθεμα ξεχωρίζει με τη γραμμή στο χρώμα της παλέτας.
    actions:
      - label: Σχετικά με εμάς
        url: about
        style: secondary
  - type: text
    align: center
    eyebrow: 'Text · default'
    heading: Κεντραρισμένο κείμενο
    body: Για σύντομες δηλώσεις ή μεταβάσεις ανάμεσα σε ενότητες.
  - type: text-image
    variant: image-left
    eyebrow: 'Text & image · image left'
    heading: Εικόνα αριστερά, πορτρέτο
    body: |
      Οι λίστες εμφανίζονται με σημάδια ελέγχου:

      - Responsive εικόνες σε WebP
      - Σωστές διαστάσεις για να μη «χοροπηδά» η σελίδα
      - Lazy loading εκτός της πρώτης οθόνης
    image: /uploads/media/5e6915a67b9ceec5.jpg
    image_alt: Αεροφωτογραφία λίμνης ανάμεσα σε βράχια
    image_ratio: portrait
  - type: features
    variant: plain
    tone: muted
    eyebrow: 'Features · plain · 4 στήλες'
    heading: Χωρίς κάρτες
    columns: '4'
    items:
      - { icon: shield, title: Ασφάλεια, text: 'Ενημερώσεις, αντίγραφα ασφαλείας και προστασία φορμών.' }
      - { icon: bolt, title: Ταχύτητα, text: 'Ελάχιστο JavaScript και εικόνες στο σωστό μέγεθος.' }
      - { icon: globe, title: Πολυγλωσσία, text: 'Κάθε σελίδα σε όσες γλώσσες χρειάζεστε.' }
      - { icon: users, title: Προσβασιμότητα, text: 'Σημασιολογικό HTML και πλήρης χρήση με πληκτρολόγιο.' }
  - type: features
    variant: numbered
    eyebrow: 'Features · numbered'
    heading: Η διαδικασία μας
    align: center
    items:
      - { title: Γνωριμία, text: 'Καταλαβαίνουμε στόχους, κοινό και περιεχόμενο.' }
      - { title: Σχεδιασμός, text: 'Προτείνουμε δομή και εμφάνιση και τα συζητάμε μαζί.' }
      - { title: Υλοποίηση, text: 'Χτίζουμε, δοκιμάζουμε και ανεβάζουμε το site.' }
  - type: stats
    variant: cards
    tone: contrast
    eyebrow: 'Stats · cards · contrast'
    heading: Αριθμοί σε κάρτες
    items:
      - { value: '6–10', label: Εβδομάδες, text: 'Μέση διάρκεια έργου' }
      - { value: '< 1s', label: Φόρτωση, text: 'Σε σύγχρονη σύνδεση' }
      - { value: '100%', label: Πληκτρολόγιο, text: 'Πλοήγηση χωρίς ποντίκι' }
  - type: testimonials
    variant: featured
    eyebrow: 'Testimonials · featured'
    items:
      - quote: Η καλύτερη απόφαση που πήραμε φέτος ήταν να ξαναφτιάξουμε το site μας από την αρχή.
        name: Γιώργος Παπαδόπουλος
        role: Γενικός Διευθυντής, Gamma Hotels
  - type: logos
    variant: grid
    tone: muted
    heading: 'Logos · grid'
    items:
      - { name: Northbridge }
      - { name: Alpha Estates }
      - { name: Beta Retail }
      - { name: Gamma Hotels }
      - { name: Aegean Labs }
      - { name: Delta Legal }
  - type: cards
    variant: list
    source: posts
    limit: 2
    eyebrow: 'Cards · list · posts'
    heading: Από το blog
    link_label: Όλα τα άρθρα
    link_url: posts
  - type: cards
    columns: '2'
    tone: muted
    eyebrow: 'Cards · manual'
    heading: Κάρτες με το χέρι
    items:
      - { title: Οδηγός επωνυμίας, text: 'Ένα PDF με χρώματα, γραμματοσειρές και παραδείγματα.', meta: Οδηγός, image: /uploads/media/faros-demo-editor.svg, url: about }
      - { title: Μελέτη περίπτωσης, text: 'Πώς ένα ξενοδοχείο διπλασίασε τις απευθείας κρατήσεις.', meta: Case study, image: /uploads/media/5e6915a67b9ceec5.jpg, url: projects }
  - type: faq
    variant: stacked
    eyebrow: 'FAQ · stacked'
    heading: Ερωτήσεις σε μία στήλη
    open_first: true
    items:
      - question: Πώς προσθέτω ένα block;
        answer: 'Προς το παρόν από το πεδίο **Front Matter** στη σελίδα επεξεργασίας. Ο οπτικός επεξεργαστής blocks έρχεται στην επόμενη φάση.'
      - question: Μπορώ να κρύψω ένα block προσωρινά;
        answer: 'Ναι, με `hidden: true`. Το block μένει αποθηκευμένο αλλά δεν εμφανίζεται.'
  - type: cta
    variant: band
    eyebrow: 'CTA · band · accent'
    heading: Ένα έντονο κάλεσμα σε όλο το πλάτος
    text: Το φόντο παίρνει το χρώμα της παλέτας από τις ρυθμίσεις του θέματος.
    actions:
      - { label: Επικοινωνία, url: contact }
      - { label: Υπηρεσίες, url: services, style: secondary }
  - type: cta
    variant: split
    tone: muted
    heading: 'CTA · split: κείμενο αριστερά, κουμπιά δεξιά'
    actions:
      - { label: Ξεκινήστε, url: contact }
  - type: form
    variant: split
    anchor: form
    eyebrow: 'Form · split'
    heading: Επικοινωνήστε μαζί μας
    intro: Συμπληρώστε τη φόρμα και θα σας απαντήσουμε μέσα σε μία εργάσιμη.
    form: contact
  - type: content
---
## Block «Page content»

Το κείμενο Markdown της σελίδας εμφανίζεται εκεί που μπαίνει το block `content`. Αν μια σελίδα με blocks δεν έχει τέτοιο block, το κείμενο εμφανίζεται αμέσως μετά το αρχικό hero.
