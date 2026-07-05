---
title: Φόρμα Επικοινωνίας
status: published
visible: true
translation_id: f0a1b2c3d4e5f678
submit_label: Αποστολή Μηνύματος
success_message: Ευχαριστούμε. Το μήνυμά σας καταχωρήθηκε και θα επικοινωνήσουμε μαζί σας σύντομα.
store_submissions: true
notifications:
  enabled: true
  to: hello@picolino.unicorg.gr
  subject: "Νέα υποβολή φόρμας επικοινωνίας"
  reply_to_field: email
  auto_reply: true
  auto_reply_include: true
  auto_reply_subject: "Λάβαμε το μήνυμά σας"
  auto_reply_message: "Ευχαριστούμε για την επικοινωνία. Η ομάδα μας θα απαντήσει το συντομότερο δυνατό."
antispam:
  honeypot: website
  rate_limit_seconds: 20
fields:
  - type: text
    name: full_name
    label: Ονοματεπώνυμο
    required: true
    placeholder: Το ονοματεπώνυμό σας
  - type: email
    name: email
    label: Email
    required: true
    placeholder: name@example.com
  - type: tel
    name: phone
    label: Τηλέφωνο
    placeholder: "+30 69X XXX XXXX"
  - type: text
    name: company
    label: Εταιρεία
    placeholder: Επωνυμία εταιρείας
  - type: select
    name: service
    label: Ενδιαφέρομαι για
    required: true
    options:
      - strategy|Στρατηγική
      - design-build|Design & Build
      - project-management|Διαχείριση Έργου
      - other|Άλλο
  - type: textarea
    name: message
    label: Μήνυμα
    required: true
    rows: 6
    placeholder: Πείτε μας λίγα λόγια για το έργο σας
  - type: checkbox
    name: consent
    label: Συμφωνώ με την επεξεργασία των στοιχείων μου για σκοπούς επικοινωνίας.
    required: true
---
