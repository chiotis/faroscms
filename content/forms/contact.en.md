---
title: Contact Form
status: published
visible: true
translation_id: f0a1b2c3d4e5f678
submit_label: Send Message
success_message: Thank you. Your message has been submitted and our team will contact you shortly.
store_submissions: true
notifications:
  enabled: true
  to: hello@picolino.unicorg.gr
  subject: "New contact form submission"
  reply_to_field: email
  auto_reply: true
  auto_reply_include: true
  auto_reply_subject: "We received your message"
  auto_reply_message: "Thanks for contacting us. Our team will get back to you as soon as possible."
antispam:
  honeypot: website
  rate_limit_seconds: 20
fields:
  - type: text
    name: full_name
    label: Full Name
    required: true
    placeholder: Your full name
  - type: email
    name: email
    label: Email
    required: true
    placeholder: name@example.com
  - type: tel
    name: phone
    label: Phone
    placeholder: "+30 69X XXX XXXX"
  - type: text
    name: company
    label: Company
    placeholder: Company name
  - type: select
    name: service
    label: Interested In
    required: true
    options:
      - strategy|Strategy
      - design-build|Design & Build
      - project-management|Project Management
      - other|Other
  - type: textarea
    name: message
    label: Message
    required: true
    rows: 6
    placeholder: Tell us a few details about your project
  - type: checkbox
    name: consent
    label: I agree to the processing of my information for communication purposes.
    required: true
---
