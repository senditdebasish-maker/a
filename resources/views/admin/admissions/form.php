<div class="page-heading admin-heading admission-create-heading">
    <div>
        <a class="back-link" href="<?= url('admin/admissions') ?>">← Admission cycles</a>
        <span class="eyebrow">Guided setup · Step 1 of 7</span>
        <h1>Start an admission notice</h1>
        <p>Set up the public notice and application window. You will configure programmes, eligibility, seats, fees, applicant fields and documents in the next steps.</p>
    </div>
</div>

<form class="card settings-panel admission-create-form" method="post" action="<?= url('admin/admissions') ?>">
    <?= csrf_field() ?>
    <header class="card-heading admission-create-card-heading">
        <div><span class="eyebrow">Cycle identity</span><h2>Build the first draft</h2><p>Fields marked with an asterisk are needed before you can move to programme setup.</p></div>
        <span class="admission-create-state">Draft setup</span>
    </header>

    <section class="admission-create-section" aria-labelledby="identity-section-title">
        <div class="admission-create-section-heading"><span>1</span><div><h3 id="identity-section-title">Name and public address</h3><p>Give staff and applicants a clear name, short code and public URL.</p></div></div>
        <div class="form-grid three">
            <label><span>Academic session *</span><select name="academic_session_id" required><option value="">Select an academic session</option><?php foreach($sessions as $session):?><option value="<?= e($session['id']) ?>"<?= selected(old('academic_session_id'),$session['id']) ?>><?= e($session['name']) ?></option><?php endforeach?></select></label>
            <label><span>Cycle name *</span><input name="name" value="<?= e(old('name')) ?>" required placeholder="Undergraduate admissions 2027–28"></label>
            <label><span>Unique code *</span><input name="code" value="<?= e(old('code')) ?>" required placeholder="UG-2027"></label>
            <label class="span-2"><span>Public slug *</span><input name="slug" value="<?= e(old('slug')) ?>" required placeholder="undergraduate-admissions-2027"></label>
            <label><span>Application-number prefix *</span><input name="application_number_prefix" value="<?= e(old('application_number_prefix','NCP-APP')) ?>" required maxlength="30" placeholder="NCP-APP"></label>
        </div>
    </section>

    <section class="admission-create-section" aria-labelledby="schedule-section-title">
        <div class="admission-create-section-heading"><span>2</span><div><h3 id="schedule-section-title">Application window</h3><p>Use local institution time. The public portal automatically opens and closes against these dates.</p></div></div>
        <div class="form-grid three">
            <label><span>Opening date &amp; time *</span><input type="datetime-local" name="starts_at" value="<?= e(old('starts_at')) ?>" required></label>
            <label><span>Closing date &amp; time *</span><input type="datetime-local" name="ends_at" value="<?= e(old('ends_at')) ?>" required></label>
            <label><span>Correction deadline</span><input type="datetime-local" name="correction_deadline" value="<?= e(old('correction_deadline')) ?>"></label>
            <label><span>Maximum preferences *</span><input type="number" min="1" max="10" name="max_program_preferences" value="<?= e(old('max_program_preferences',3)) ?>" required></label>
            <label><span>Closing-soon warning (hours)</span><input type="number" min="0" max="720" name="closing_soon_hours" value="<?= e(old('closing_soon_hours',72)) ?>"></label>
        </div>
    </section>

    <section class="admission-create-section admission-create-copy" aria-labelledby="copy-section-title">
        <div class="admission-create-section-heading"><span>3</span><div><h3 id="copy-section-title">Applicant-facing guidance</h3><p>This copy appears in the notice and application experience. Keep it concise and institution-approved.</p></div></div>
        <div class="form-grid">
            <label class="full"><span>Public admission summary *</span><textarea name="summary" rows="3" required placeholder="Briefly explain who may apply and what this admission cycle covers."><?= e(old('summary')) ?></textarea></label>
            <label class="full"><span>Applicant instructions *</span><textarea name="instructions" rows="5" required placeholder="Explain the application steps, important dates and what applicants should prepare."><?= e(old('instructions')) ?></textarea></label>
            <label class="full"><span>Applicant declaration *</span><textarea name="declaration_text" rows="4" required placeholder="Declaration applicants must accept before final submission."><?= e(old('declaration_text')) ?></textarea></label>
        </div>
    </section>

    <footer class="form-actions">
        <a href="<?= url('admin/admissions') ?>">Cancel and return to admission cycles</a>
        <button class="button button-primary">Create draft &amp; continue to programmes →</button>
    </footer>
</form>
