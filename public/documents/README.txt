BNPL Guarantor Form PDFs (two flows)

Upload from Admin → BNPL → Form, or place files here:

  guarantor-form-residential.pdf  — Residential / Individual applications
  guarantor-form-sme.pdf          — SME applications (also used for Commercial)

Legacy (optional Residential fallback if residential file is missing):
  guarantor-form.pdf

Env overrides (relative to public/):
  GUARANTOR_FORM_PATH_RESIDENTIAL=documents/guarantor-form-residential.pdf
  GUARANTOR_FORM_PATH_SME=documents/guarantor-form-sme.pdf
  GUARANTOR_FORM_PATH=documents/guarantor-form.pdf

Customers download the form matching their application customer_type after loan approval.
