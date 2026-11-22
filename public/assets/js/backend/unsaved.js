// Drafts in the backend: the bar "Unsaved changes" and the warning before leaving.
// isDirty decides; call the returned update() after every change.

export const guardDraft = ({ bar, isDirty, onUpdate = () => {} }) => {
  let leaving = false;
  const update = () => {
    const dirty = isDirty();
    if (bar) {
      bar.hidden = !dirty;
    }
    onUpdate(dirty);
  };

  window.addEventListener('beforeunload', (event) => {
    if (!leaving && isDirty()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // allowLeave(): for a form submit or a deliberate reload, no warning.
  return { update, allowLeave: () => { leaving = true; } };
};
