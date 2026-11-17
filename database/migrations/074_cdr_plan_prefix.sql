-- Persist dial plan prefix (PP) on CDRs for display + rating after OpenSIPS strips it for routing.
ALTER TABLE cdrs
    ADD COLUMN plan_prefix VARCHAR(32) NULL AFTER dst_prefix;

ALTER TABLE cdrs
    ADD KEY ix_cdr_plan_prefix (plan_prefix);
