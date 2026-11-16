import styles from './FormField.module.scss'

export default function FormField({
  as: Control = 'input',
  id,
  label,
  hint,
  errors,
  ...props
}) {
  const hasError = Boolean(errors?.length)
  const descriptions = [hint && `${id}-hint`, hasError && `${id}-error`]
    .filter(Boolean)
    .join(' ')

  return (
    <div className={styles.field}>
      <label htmlFor={id}>{label}</label>
      {hint && (
        <p id={`${id}-hint`} className={styles.hint}>
          {hint}
        </p>
      )}
      <Control
        id={id}
        name={id}
        aria-invalid={hasError}
        aria-describedby={descriptions || undefined}
        {...props}
      />
      {hasError && (
        <p id={`${id}-error`} className={styles.error}>
          {errors.join(' ')}
        </p>
      )}
    </div>
  )
}
