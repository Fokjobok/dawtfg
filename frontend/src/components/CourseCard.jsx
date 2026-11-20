import styles from './CourseCard.module.scss'

const currency = new Intl.NumberFormat('es-ES', {
  style: 'currency',
  currency: 'EUR',
})

export default function CourseCard({ course }) {
  const price = Number(course.price)

  return (
    <article
      className={styles.card}
      aria-labelledby={`course-${course.id}-title`}
    >
      <div className={styles.media}>
        {course.cover_url && (
          <img
            src={course.cover_url}
            alt=""
            loading="lazy"
            decoding="async"
            width={640}
            height={320}
          />
        )}
      </div>

      <div className={styles.content}>
        <p>{course.specialty.name}</p>

        <h2 id={`course-${course.id}-title`}>{course.title}</h2>

        <p className={styles.description}>{course.description}</p>
        <p>Coach: {course.coach.name}</p>

        <p className={styles.price}>
          {price === 0 ? 'Gratuito' : currency.format(price)}
        </p>
      </div>
    </article>
  )
}
